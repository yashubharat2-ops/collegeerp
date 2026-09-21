<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeReportService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Models\FeeReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fee Reports (Finance / Fees).
 *
 * Read-only report screen. Every report is aggregated live from the existing
 * transactional records — there is no reporting table and no second copy of a
 * financial fact. Ledger-based reports come from the same FeeDuesService the Due
 * screen uses, so a report can never disagree with the screen.
 *
 * Cancelled/reversed collections are never counted as collected.
 */
class FeeReportController extends Controller
{
    /** The reports the screen can render. */
    public const REPORTS = [
        'collection' => 'Collection Summary',
        'dues' => 'Due / Outstanding Summary',
        'student' => 'Student Fee Report',
        'program' => 'Program-wise Fee Report',
        'mode' => 'Payment Mode Report',
        'date' => 'Date-wise Collection Report',
    ];

    public function __construct(private readonly FeeReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeReport::class);

        $report = $request->input('report');
        $report = array_key_exists((string) $report, self::REPORTS) ? (string) $report : 'collection';

        $filters = [
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'student_id' => $request->input('student_id'),
            'student_enrollment_id' => $request->input('student_enrollment_id'),
            'fee_structure_id' => $request->input('fee_structure_id'),
            'payment_mode' => $request->input('payment_mode'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'status' => $request->input('status'),
        ];

        $data = [];

        switch ($report) {
            case 'dues':
                $data['dueSummary'] = $this->reports->dueSummary($filters);
                break;

            case 'student':
                $data['studentReport'] = $this->reports->studentFeeReport($filters, 15);
                break;

            case 'program':
                $data['programReport'] = $this->reports->programWise($filters);
                break;

            case 'mode':
                $data['modeReport'] = $this->reports->paymentModeSummary($filters);
                break;

            case 'date':
                $data['dateReport'] = $this->reports->dateWiseCollection($filters);
                break;

            default:
                $data['collectionSummary'] = $this->reports->collectionSummary($filters);
                break;
        }

        return view('fee_reports.index', array_merge(FeeFormOptions::all(), $data, [
            'college' => app(TenantContext::class)->college(),
            'report' => $report,
            'reports' => self::REPORTS,
            'selected' => $filters,
        ]));
    }
}
