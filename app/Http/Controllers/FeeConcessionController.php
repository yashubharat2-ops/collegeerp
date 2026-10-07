<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeConcessionService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Http\Requests\FeeConcession\StoreFeeConcessionRequest;
use App\Http\Requests\FeeConcession\UpdateFeeConcessionRequest;
use App\Models\FeeConcession;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fee Discounts / Concessions (Finance / Fees).
 *
 * Concession amounts are computed server-side from the assignment snapshot; the
 * browser submits only the type and the value. Approval is a separate action
 * behind its own permission, and the approval metadata is written only there.
 */
class FeeConcessionController extends Controller
{
    public function __construct(private readonly FeeConcessionService $concessions)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeConcession::class);

        $query = FeeConcession::query()
            ->with(['studentFeeAssignment.studentEnrollment.student', 'studentFeeAssignment.studentEnrollment.program', 'studentFeeAssignment.feeStructure', 'approver'])
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($request->input('program_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($request->input('student_fee_assignment_id'), fn (Builder $q, $value) => $q->where('student_fee_assignment_id', $value))
            ->when(in_array($request->input('status'), FeeConcession::STATUSES, true), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when(in_array($request->input('type'), FeeConcession::TYPES, true), fn (Builder $q) => $q->where('type', $request->input('type')))
            // Deterministic pagination order.
            ->orderByDesc('id');

        return view('fee_concessions.index', array_merge(FeeFormOptions::all(), [
            'concessions' => $query->paginate(15)->withQueryString(),
            'selected' => [
                'student_id' => $request->input('student_id'),
                'academic_year_id' => $request->input('academic_year_id'),
                'program_id' => $request->input('program_id'),
                'student_fee_assignment_id' => $request->input('student_fee_assignment_id'),
                'status' => $request->input('status'),
                'type' => $request->input('type'),
            ],
        ]));
    }

    /**
     * CSV export of a bulk selection from the Concessions / Discounts list.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and the module
     * permission is re-checked here. Read-only: approving a concession changes
     * what a student owes, so it keeps its own permitted action, its own service
     * rules and its own audit trail.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', FeeConcession::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $concessions = FeeConcession::query()
            ->with([
                'studentFeeAssignment.studentEnrollment.student',
                'studentFeeAssignment.studentEnrollment.program',
                'studentFeeAssignment.feeStructure',
                'approver',
            ])
            ->whereIn('fee_concessions.id', $ids)
            ->orderBy('fee_concessions.id')
            ->get();

        $rows = $concessions->map(function (FeeConcession $concession): array {
            $assignment = $concession->studentFeeAssignment;

            return [
                $assignment?->studentEnrollment?->student?->fullName(),
                $assignment?->studentEnrollment?->enrollment_number,
                $assignment?->feeStructure?->name,
                $concession->type,
                $concession->value,
                $concession->amount,
                $concession->reason,
                $concession->status,
                $concession->approved_at?->format('Y-m-d'),
                $concession->approver?->name,
            ];
        });

        $audit->record('fee_concessions.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $concessions->count(),
        ]);

        return CsvStreamExport::make('fee-concessions-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Student', 'Enrollment', 'Fee structure', 'Type', 'Value', 'Amount',
                'Reason', 'Status', 'Approved at', 'Approved by',
            ])
            ->streamFromCollection($rows);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', FeeConcession::class);

        $assignment = null;

        if ($assignmentId = $request->input('student_fee_assignment_id')) {
            $assignment = StudentFeeAssignment::query()
                ->with(['studentEnrollment.student', 'studentEnrollment.program', 'feeStructure'])
                ->find($assignmentId);
        }

        return view('fee_concessions.create', array_merge(FeeFormOptions::all(), [
            'assignment' => $assignment,
            'selectedAssignmentId' => $assignmentId,
        ]));
    }

    public function store(StoreFeeConcessionRequest $request): RedirectResponse
    {
        app(TenantContext::class)->require();

        $assignment = StudentFeeAssignment::query()->findOrFail($request->validated()['student_fee_assignment_id']);

        $concession = $this->concessions->create($assignment, $request->validated(), $request->user());

        return redirect()
            ->route('fee-concessions.index')
            ->with('success', 'Concession of '.number_format((float) $concession->amount, 2).' recorded ('.$concession->status.').');
    }

    public function edit(string $fee_concession): View
    {
        $concession = $this->findScoped($fee_concession);
        $this->authorize('update', $concession);

        $concession->load(['studentFeeAssignment.studentEnrollment.student', 'studentFeeAssignment.studentEnrollment.program', 'studentFeeAssignment.feeStructure']);

        return view('fee_concessions.edit', array_merge(FeeFormOptions::all(), [
            'concession' => $concession,
        ]));
    }

    public function update(UpdateFeeConcessionRequest $request, string $fee_concession): RedirectResponse
    {
        $concession = $this->findScoped($fee_concession);

        $this->concessions->update($concession, $request->validated(), $request->user());

        return redirect()
            ->route('fee-concessions.index')
            ->with('success', 'Concession updated.');
    }

    public function approve(string $fee_concession): RedirectResponse
    {
        $concession = $this->findScoped($fee_concession);
        $this->authorize('approve', $concession);

        $this->concessions->approve($concession, request()->user());

        return redirect()
            ->route('fee-concessions.index')
            ->with('success', 'Concession approved.');
    }

    public function destroy(string $fee_concession): RedirectResponse
    {
        $concession = $this->findScoped($fee_concession);
        $this->authorize('delete', $concession);

        $this->concessions->delete($concession, request()->user());

        return redirect()
            ->route('fee-concessions.index')
            ->with('success', 'Concession deleted.');
    }

    private function findScoped(string $id): FeeConcession
    {
        return FeeConcession::query()->findOrFail($id);
    }
}
