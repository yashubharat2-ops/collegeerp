<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Student\Filters\StudentFilter;
use App\Models\FeeDue;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Due / Outstanding Fees (Finance / Fees).
 *
 * Read-only by design: the screen derives every figure from the assignment
 * snapshot plus the transaction rows through FeeDuesService, so there is no
 * stored balance and nothing to edit. Cancelled/reversed payments are excluded,
 * valid refunds reduce the collected amount, and concessions that were rejected
 * or cancelled no longer apply.
 */
class FeeDueController extends Controller
{
    public function __construct(
        private readonly FeeDuesService $dues,
        private readonly StudentFilter $studentFilter,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeDue::class);

        $filters = $this->filters($request);
        $dues = $this->dues->paginate($filters, 15);
        $selected = $filters;
        unset($selected['student_filters']);

        return view('fee_dues.index', array_merge(FeeFormOptions::all(), $this->studentFilter->viewData($filters['student_filters'], StudentFilter::STUDENT_LIST_FIELDS, $request->user()), [
            'college' => app(TenantContext::class)->college(),
            'dues' => $dues,
            'totals' => $this->dues->totals($filters),
            'ledgerStatuses' => FeeLedger::STATUSES,
            'selected' => $selected,
        ]));
    }

    /**
     * CSV export of a bulk selection from the Due / Outstanding list.
     *
     * The dues screen has no rows of its own: each line is a live ledger over one
     * StudentFeeAssignment, which is what the selection posts. Ids are treated as
     * a request, never as data (normalised, capped, re-queried inside the active
     * college through the model's college scope) and the module permission is
     * re-checked here. Every figure is rebuilt by FeeDuesService for exactly
     * those ids — the same service the screen uses, so no balance is calculated
     * twice and nothing is written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', FeeDue::class);
        $filters = $this->filters($request);
        $ids = ListSelection::ids($request->input('ids', []));
        $query = $this->dues->query($filters)
            ->when($ids !== [], fn ($builder) => $builder->whereIn('student_fee_assignments.id', $ids))
            ->reorder('student_fee_assignments.id', 'asc');

        $audit->record('fee_dues.exported', null, [], [
            'selected_ids' => count($ids),
            'filters' => $this->studentFilter->auditSafe($filters['student_filters']),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('fee-dues-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Student', 'Enrollment', 'Year', 'Program', 'Fee structure', 'Fee Head',
                'Assigned', 'Concession', 'Paid', 'Refunded', 'Outstanding', 'Status',
            ])
            ->map(function (StudentFeeAssignment $assignment): array {
                $summary = $this->dues->summaryFromRow($assignment);

                return [
                    $assignment->studentEnrollment?->student?->fullName(),
                    $assignment->studentEnrollment?->enrollment_number,
                    $assignment->studentEnrollment?->academicYear?->name,
                    $assignment->studentEnrollment?->program?->code,
                    $assignment->feeStructure?->name,
                    $assignment->isPerFeeHead() ? $assignment->feeHeadLabel() : 'Whole structure',
                    $summary['assigned'] ?? 0,
                    $summary['concession'] ?? 0,
                    $summary['paid'] ?? 0,
                    $summary['refunded'] ?? 0,
                    $summary['outstanding'] ?? 0,
                    $assignment->status,
                ];
            })
            ->streamFromQuery($query);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'student_id' => $request->input('student_id'),
            'student_enrollment_id' => $request->input('student_enrollment_id'),
            'fee_structure_id' => $request->input('fee_structure_id'),
            'status' => $request->input('status'),
            'assignment_status' => $request->input('assignment_status'),
            'student_filters' => $this->studentFilter->fromRequest($request, user: $request->user()),
        ];
    }
}
