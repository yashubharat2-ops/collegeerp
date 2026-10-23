<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Services\FeeHeadAllocationService;
use App\Domain\Finance\Services\StudentFeeAssignmentService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Student\Filters\StudentFilter;
use App\Http\Requests\StudentFeeAssignment\PreviewFeeHeadAllocationRequest;
use App\Http\Requests\StudentFeeAssignment\StoreStudentFeeAssignmentRequest;
use App\Http\Requests\StudentFeeAssignment\UpdateStudentFeeAssignmentRequest;
use App\Models\FeeStructure;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Student Fee Assignment (Finance / Fees).
 *
 * Assigns an existing fee structure to an existing enrollment. The academic
 * context (student, year, program, term) is never copied — it stays owned by
 * StudentEnrollment — and the assigned amount is computed server-side.
 *
 * Every row on the list shows its live ledger position (concessions, collected,
 * outstanding) from FeeDuesService, i.e. from the same calculation the Due /
 * Outstanding screen uses.
 */
class StudentFeeAssignmentController extends Controller
{
    public function __construct(
        private readonly StudentFeeAssignmentService $assignments,
        private readonly FeeDuesService $dues,
    ) {
    }

    public function index(Request $request, StudentFilter $studentFilter): View
    {
        $this->authorize('viewAny', StudentFeeAssignment::class);
        $studentFilters = $studentFilter->fromRequest($request, user: $request->user());

        $query = $this->filteredAssignmentQuery($request, $studentFilter, $studentFilters)
            ->with(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure'])
            // Deterministic pagination order.
            ->orderByDesc('student_fee_assignments.id');

        $assignments = $query->paginate(15)->withQueryString();
        $ledger = $this->dues->ledgerFor($assignments->getCollection());

        $assignments->getCollection()->each(function (StudentFeeAssignment $assignment) use ($ledger): void {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        });

        return view('student_fee_assignments.index', array_merge(FeeFormOptions::all(), $studentFilter->viewData($studentFilters, StudentFilter::STUDENT_LIST_FIELDS, $request->user()), [
            'assignments' => $assignments,
            'selected' => [
                'student_id' => $request->input('student_id'),
                'academic_year_id' => $request->input('academic_year_id'),
                'program_id' => $request->input('program_id'),
                'fee_structure_id' => $request->input('fee_structure_id'),
                'status' => $request->input('status'),
            ],
        ]));
    }

    /**
     * CSV export of a bulk selection from the Student Fee Assignments list.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and the module
     * permission is re-checked here. The money columns come from the same
     * FeeDuesService ledger the listing uses — the export never adds up a balance
     * of its own — and nothing is written: assignments are changed one at a time.
     */
    public function export(Request $request, AuditLogService $audit, StudentFilter $studentFilter): StreamedResponse
    {
        $this->authorize('viewAny', StudentFeeAssignment::class);
        $studentFilters = $studentFilter->fromRequest($request, user: $request->user());
        $ids = ListSelection::ids($request->input('ids', []));

        $query = $this->filteredAssignmentQuery($request, $studentFilter, $studentFilters)
            ->with(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure'])
            ->when($ids !== [], fn (Builder $builder) => $builder->whereIn('student_fee_assignments.id', $ids))
            ->orderBy('student_fee_assignments.id');

        $audit->record('student_fee_assignments.exported', null, [], [
            'selected_ids' => count($ids),
            'filters' => $studentFilter->auditSafe($studentFilters),
            'rows' => (clone $query)->count(),
        ]);

        // Chunk both assignments and their shared ledger aggregation. An
        // all-filtered export is streamed just like a page selection.
        $rows = (function () use ($query): \Generator {
            $afterId = 0;
            do {
                $assignments = (clone $query)
                    ->where('student_fee_assignments.id', '>', $afterId)
                    ->limit(200)->get();
                $ledger = $this->dues->ledgerFor($assignments);
                foreach ($assignments as $assignment) {
                    $summary = $ledger[$assignment->getKey()] ?? [];
                    yield [
                        $assignment->studentEnrollment?->student?->fullName(),
                        $assignment->studentEnrollment?->enrollment_number,
                        $assignment->studentEnrollment?->academicYear?->name,
                        $assignment->studentEnrollment?->program?->code,
                        $assignment->feeStructure?->name,
                        $assignment->isPerFeeHead() ? $assignment->feeHeadLabel() : 'Whole structure',
                        $assignment->assigned_amount,
                        $summary['concession'] ?? 0,
                        $summary['net_collected'] ?? 0,
                        $summary['outstanding'] ?? 0,
                        $assignment->status,
                    ];
                }
                $afterId = (int) ($assignments->last()?->getKey() ?? $afterId);
            } while ($assignments->count() === 200);
        })();

        return CsvStreamExport::make('student-fee-assignments-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Student', 'Enrollment', 'Year', 'Program', 'Fee structure', 'Fee Head',
                'Assigned', 'Concession', 'Collected', 'Outstanding', 'Status',
            ])
            ->streamFromCollection($rows);
    }

    /** Filter students and configure a per-Fee-Head allocation preview. */
    public function allocate(Request $request, StudentFilter $studentFilter): View
    {
        $this->authorize('create', StudentFeeAssignment::class);
        $filters = $studentFilter->fromRequest($request, user: $request->user());

        $enrollments = $studentFilter->applyToEnrollments(
            StudentEnrollment::query()->with(['student', 'academicYear', 'program', 'section']),
            $filters,
        )->orderBy('student_id')->orderBy('id')->paginate(15)->withQueryString();

        $structures = FeeStructure::query()
            ->with(['activeItems' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')->orderBy('id')->get();

        return view('student_fee_assignments.allocate', array_merge(
            $studentFilter->viewData($filters, StudentFilter::STUDENT_LIST_FIELDS, $request->user()),
            [
                'enrollments' => $enrollments,
                'structures' => $structures,
                'filters' => $filters,
                'selectedStructureId' => $request->query('fee_structure_id'),
            ],
        ));
    }

    /** Build a complete server-side preview; this endpoint writes nothing. */
    public function previewAllocation(
        PreviewFeeHeadAllocationRequest $request,
        StudentFilter $studentFilter,
        FeeHeadAllocationService $allocator,
    ): View {
        $filters = $studentFilter->fromArray($request->all(), user: $request->user());
        $payload = array_merge($request->validated(), [
            'filters' => $filters,
            'batch_id' => (string) Str::uuid(),
            'college_id' => app(TenantContext::class)->require()->getKey(),
            'user_id' => $request->user()->getKey(),
            'expires_at' => now()->addMinutes(30)->getTimestamp(),
        ]);
        $result = $allocator->preview(app(TenantContext::class)->require(), $payload, $request->user());
        // Bind confirmation to the exact reviewed head labels/amounts. Eligibility
        // is still re-run, but a price edit requires a fresh preview.
        $payload['head_snapshots'] = $result['heads'];

        return view('student_fee_assignments.allocation_preview', [
            'result' => $result,
            'allocationToken' => $this->encodeAllocationToken($payload),
            'filters' => $filters,
        ]);
    }

    /** Confirm the signed server payload; retries return the committed batch. */
    public function confirmAllocation(Request $request, FeeHeadAllocationService $allocator): View
    {
        $this->authorize('create', StudentFeeAssignment::class);
        $validated = $request->validate(['allocation_token' => ['required', 'string']]);
        $payload = $this->decodeAllocationToken($validated['allocation_token'], $request);
        $result = $allocator->confirm(app(TenantContext::class)->require(), $payload, $request->user());

        return view('student_fee_assignments.allocation_result', ['result' => $result]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentFeeAssignment::class);

        return view('student_fee_assignments.create', array_merge(FeeFormOptions::all(), [
            'selectedEnrollmentId' => $request->input('student_enrollment_id'),
            'selectedStructureId' => $request->input('fee_structure_id'),
        ]));
    }

    public function store(StoreStudentFeeAssignmentRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $assignment = $this->assignments->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('student-fee-assignments.index')
            ->with('success', 'Fee structure assigned to the student enrollment.');
    }

    public function edit(string $student_fee_assignment): View
    {
        $assignment = $this->findScoped($student_fee_assignment);
        $this->authorize('update', $assignment);

        $assignment->load(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure']);

        return view('student_fee_assignments.edit', array_merge(FeeFormOptions::all(), [
            'assignment' => $assignment,
            'ledger' => $this->dues->summaryFor($assignment),
        ]));
    }

    public function update(UpdateStudentFeeAssignmentRequest $request, string $student_fee_assignment): RedirectResponse
    {
        $assignment = $this->findScoped($student_fee_assignment);

        $this->assignments->update($assignment, $request->validated(), $request->user());

        return redirect()
            ->route('student-fee-assignments.index')
            ->with('success', 'Fee assignment updated.');
    }

    public function destroy(string $student_fee_assignment): RedirectResponse
    {
        $assignment = $this->findScoped($student_fee_assignment);
        $this->authorize('delete', $assignment);

        $this->assignments->delete($assignment, request()->user());

        return redirect()
            ->route('student-fee-assignments.index')
            ->with('success', 'Fee assignment deleted. Its collected payments stay in the collection register.');
    }

    private function filteredAssignmentQuery(Request $request, StudentFilter $studentFilter, array $studentFilters): Builder
    {
        $query = StudentFeeAssignment::query();
        $studentFilter->applyThroughEnrollment($query, $studentFilters);

        return $query
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment', fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('fee_structure_id'), fn (Builder $q, $value) => $q->where('student_fee_assignments.fee_structure_id', $value))
            ->when(
                in_array($request->input('status'), StudentFeeAssignment::STATUSES, true),
                fn (Builder $q) => $q->where('student_fee_assignments.status', $request->input('status'))
            );
    }

    /** @param array<string, mixed> $payload */
    private function encodeAllocationToken(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decodeAllocationToken(string $token, Request $request): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['allocation_token' => 'This allocation preview is invalid. Please preview again.']);
        }

        $required = ['batch_id', 'college_id', 'user_id', 'expires_at', 'fee_structure_id', 'fee_head_ids', 'selection_mode', 'student_enrollment_ids', 'assigned_at', 'status', 'filters', 'head_snapshots'];
        if (! is_array($payload) || array_diff($required, array_keys($payload)) !== []
            || (int) $payload['expires_at'] < now()->getTimestamp()
            || (int) $payload['college_id'] !== (int) app(TenantContext::class)->require()->getKey()
            || (int) $payload['user_id'] !== (int) $request->user()->getKey()
            || ! is_string($payload['batch_id']) || ! Str::isUuid($payload['batch_id'])) {
            throw ValidationException::withMessages(['allocation_token' => 'This allocation preview expired or belongs to another user/college. Please preview again.']);
        }

        return $payload;
    }

    private function findScoped(string $id): StudentFeeAssignment
    {
        return StudentFeeAssignment::query()->findOrFail($id);
    }
}
