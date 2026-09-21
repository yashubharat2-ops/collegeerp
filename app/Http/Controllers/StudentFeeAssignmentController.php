<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Services\StudentFeeAssignmentService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Http\Requests\StudentFeeAssignment\StoreStudentFeeAssignmentRequest;
use App\Http\Requests\StudentFeeAssignment\UpdateStudentFeeAssignmentRequest;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentFeeAssignment::class);

        $query = StudentFeeAssignment::query()
            ->with(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure'])
            ->when($request->input('student_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($request->input('academic_year_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($request->input('program_id'), fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($request->input('fee_structure_id'), fn (Builder $q, $value) => $q->where('fee_structure_id', $value))
            ->when(
                in_array($request->input('status'), StudentFeeAssignment::STATUSES, true),
                fn (Builder $q) => $q->where('status', $request->input('status'))
            )
            // Deterministic pagination order.
            ->orderByDesc('id');

        $assignments = $query->paginate(15)->withQueryString();
        $ledger = $this->dues->ledgerFor($assignments->getCollection());

        $assignments->getCollection()->each(function (StudentFeeAssignment $assignment) use ($ledger): void {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        });

        return view('student_fee_assignments.index', array_merge(FeeFormOptions::all(), [
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

    private function findScoped(string $id): StudentFeeAssignment
    {
        return StudentFeeAssignment::query()->findOrFail($id);
    }
}
