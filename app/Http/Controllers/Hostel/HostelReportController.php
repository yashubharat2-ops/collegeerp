<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelReportService;
use App\Domain\Hostel\Support\HostelFormOptions;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\HostelAttendance;
use App\Models\HostelReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Hostel Reports (Hostel Management Phase 3) — READ-ONLY.
 *
 * Every report is aggregated live from existing Hostel, Student and Finance
 * records. There is no reporting table and no write route: POST/PUT/DELETE
 * are not registered, so nothing on the screen can be edited.
 */
class HostelReportController extends Controller
{
    public const REPORTS = [
        'occupancy' => 'Occupancy Summary',
        'allocations' => 'Active Allocation Summary',
        'attendance' => 'Attendance Summary',
        'fees' => 'Hostel Fee Summary',
    ];

    public function __construct(private readonly HostelReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelReport::class);

        $validated = $request->validate([
            'hostel_id' => ['nullable', 'integer'],
            'hostel_building_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
            'academic_term_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'attendance_status' => ['nullable', Rule::in(HostelAttendance::STATUSES)],
        ]);

        $report = (string) $request->input('report', 'occupancy');
        if (! array_key_exists($report, self::REPORTS)) {
            $report = 'occupancy';
        }

        $filters = [
            'hostel_id' => $validated['hostel_id'] ?? null,
            'hostel_building_id' => $validated['hostel_building_id'] ?? null,
            'academic_year_id' => $validated['academic_year_id'] ?? null,
            'academic_term_id' => $validated['academic_term_id'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'attendance_status' => $validated['attendance_status'] ?? null,
        ];

        $data = match ($report) {
            'allocations' => ['allocationReport' => $this->reports->allocations($filters)],
            'attendance' => ['attendanceReport' => $this->reports->attendance($filters)],
            'fees' => ['feeReport' => $this->reports->fees($filters)],
            default => ['occupancy' => $this->reports->occupancy($filters)],
        };

        return view('hostel_reports.index', array_merge($data, [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'hostels' => HostelFormOptions::hostels(),
            'buildings' => HostelFormOptions::buildings(),
            'years' => AcademicYear::query()->orderByDesc('starts_on')->orderByDesc('id')->get(['id', 'name', 'code']),
            'terms' => AcademicTerm::query()->with('academicYear:id,name')->orderBy('academic_year_id')->orderBy('sequence')->orderBy('id')->get(['id', 'academic_year_id', 'name', 'code']),
            'statuses' => HostelAttendance::STATUSES,
        ]));
    }
}
