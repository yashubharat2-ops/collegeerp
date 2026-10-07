<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Finance\Support\FeeLedger;
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
    public function __construct(private readonly FeeDuesService $dues)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeDue::class);

        $filters = [
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'student_id' => $request->input('student_id'),
            'student_enrollment_id' => $request->input('student_enrollment_id'),
            'fee_structure_id' => $request->input('fee_structure_id'),
            'status' => $request->input('status'),
            'assignment_status' => $request->input('assignment_status'),
        ];

        $dues = $this->dues->paginate($filters, 15);

        return view('fee_dues.index', array_merge(FeeFormOptions::all(), [
            'college' => app(TenantContext::class)->college(),
            'dues' => $dues,
            'totals' => $this->dues->totals($filters),
            'ledgerStatuses' => FeeLedger::STATUSES,
            'selected' => $filters,
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

        $ids = ListSelection::ids($request->input('ids', []));

        $assignments = StudentFeeAssignment::query()
            ->with(['studentEnrollment.student', 'studentEnrollment.academicYear', 'studentEnrollment.program', 'feeStructure'])
            ->whereIn('student_fee_assignments.id', $ids)
            ->orderBy('student_fee_assignments.id')
            ->get();

        $ledger = $this->dues->ledgerFor($assignments);

        $rows = $assignments->map(function (StudentFeeAssignment $assignment) use ($ledger): array {
            $summary = $ledger[$assignment->getKey()] ?? [];

            return [
                $assignment->studentEnrollment?->student?->fullName(),
                $assignment->studentEnrollment?->enrollment_number,
                $assignment->studentEnrollment?->academicYear?->name,
                $assignment->studentEnrollment?->program?->code,
                $assignment->feeStructure?->name,
                $summary['assigned'] ?? 0,
                $summary['concession'] ?? 0,
                $summary['paid'] ?? 0,
                $summary['refunded'] ?? 0,
                $summary['outstanding'] ?? 0,
                $assignment->status,
            ];
        });

        $audit->record('fee_dues.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $assignments->count(),
        ]);

        return CsvStreamExport::make('fee-dues-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Student', 'Enrollment', 'Year', 'Program', 'Fee structure',
                'Assigned', 'Concession', 'Paid', 'Refunded', 'Outstanding', 'Status',
            ])
            ->streamFromCollection($rows);
    }
}
