<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeDuesService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeeDue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
}
