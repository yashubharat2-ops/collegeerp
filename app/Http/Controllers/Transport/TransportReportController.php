<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\TransportReportService;
use App\Http\Controllers\Controller;
use App\Models\{AcademicYear, TransportReport, TransportRoute, TransportStop, Vehicle};
use Illuminate\Http\{Request};
use Illuminate\View\View;

/**
 * Transport Reports (Transport Phase 2) — READ-ONLY.
 *
 * Every report is aggregated live from the existing Transport, Student and
 * Finance records; there is no reporting table and no report rows, so nothing
 * on the screen can be edited (there are no POST/PUT/DELETE routes at all).
 */
class TransportReportController extends Controller
{
    /** The reports the screen can render. */
    public const REPORTS = [
        'vehicle_summary' => 'Vehicle Summary',
        'vehicle_status' => 'Vehicle Status',
        'document_expiry' => 'Vehicle Document Expiry',
        'driver_summary' => 'Driver Summary',
        'route_summary' => 'Route Summary',
        'stop_students' => 'Stop-wise Student Count',
        'assignments' => 'Student Transport Assignment Report',
        'fee_summary' => 'Transport Fee Summary',
        'fee_outstanding' => 'Transport Fee Outstanding Summary',
        'active_inactive' => 'Active / Inactive Transport Assignments',
    ];

    public function __construct(private readonly TransportReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TransportReport::class);

        $report = (string) $request->input('report');
        $report = array_key_exists($report, self::REPORTS) ? $report : 'vehicle_summary';

        $filters = [
            'academic_year_id' => $request->input('academic_year_id'),
            'route_id' => $request->input('route_id'),
            'stop_id' => $request->input('stop_id'),
            'vehicle_id' => $request->input('vehicle_id'),
            'status' => $request->input('status'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'student_id' => $request->input('student_id'),
        ];

        $data = match ($report) {
            'vehicle_status' => ['statusReport' => $this->reports->vehicleStatus($filters)],
            'document_expiry' => ['documentReport' => $this->reports->vehicleDocumentExpiry($filters)],
            'driver_summary' => ['driverReport' => $this->reports->driverSummary($filters)],
            'route_summary' => ['routeReport' => $this->reports->routeSummary($filters)],
            'stop_students' => ['stopReport' => $this->reports->stopWiseStudentCount($filters)],
            'assignments' => ['assignmentReport' => $this->reports->studentAssignmentReport($filters)],
            'fee_summary' => $this->reports->transportFeeSummary($filters) + ['feeReport' => true],
            'fee_outstanding' => $this->reports->transportFeeOutstandingSummary($filters) + ['feeReport' => true],
            'active_inactive' => ['activeInactiveReport' => $this->reports->activeInactiveAssignments($filters)],
            default => ['vehicleReport' => $this->reports->vehicleSummary($filters)],
        };

        return view('transport.reports.index', array_merge($data, [
            'report' => $report,
            'reports' => self::REPORTS,
            'selected' => $filters,
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'routes' => TransportRoute::query()->orderBy('name')->get(['id', 'name', 'code']),
            'stops' => TransportStop::query()->orderBy('route_id')->orderBy('sequence')->get(['id', 'route_id', 'name', 'code']),
            'vehicles' => Vehicle::query()->orderBy('registration_number')->get(['id', 'registration_number']),
        ]));
    }
}
