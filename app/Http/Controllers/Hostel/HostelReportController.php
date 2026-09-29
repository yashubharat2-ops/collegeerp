<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Hostel\Services\HostelReportService;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelReport;
use App\Models\HostelRoom;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Read-only Hostel Reports, separate from operational Hostel screens. */
class HostelReportController extends Controller
{
    /** The exact Hostel Reports workflow, in required navigation order. */
    public const REPORTS = [
        'hostels' => 'Hostel / Building Report',
        'occupancy' => 'Room / Bed Occupancy Report',
        'allocations' => 'Hostel Allocation Report',
        'attendance' => 'Hostel Attendance Report',
        'fees' => 'Hostel Fee Report',
        'vacated' => 'Vacated Student Report',
        'summary' => 'Hostel Summary',
    ];

    /** Filters are offered only where the selected report can apply them. */
    public const FILTERS = [
        'hostels' => ['hostel_id', 'hostel_building_id'],
        'occupancy' => ['academic_year_id', 'academic_term_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id', 'from', 'to'],
        'allocations' => ['academic_year_id', 'academic_term_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id', 'student_id', 'program_id', 'section_id', 'allocation_status', 'from', 'to'],
        'attendance' => ['academic_year_id', 'academic_term_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id', 'student_id', 'program_id', 'section_id', 'attendance_status', 'from', 'to'],
        'fees' => ['academic_year_id', 'academic_term_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id', 'student_id', 'program_id', 'section_id', 'fee_status', 'from', 'to'],
        'vacated' => ['academic_year_id', 'academic_term_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id', 'student_id', 'program_id', 'section_id', 'from', 'to'],
        'summary' => [],
    ];

    public const DATE_LABELS = [
        'occupancy' => 'Allocation overlap',
        'allocations' => 'Allocation date',
        'attendance' => 'Attendance date',
        'fees' => 'Fee effective date',
        'vacated' => 'Vacated date',
    ];

    private const ID_FILTERS = [
        'academic_year_id', 'academic_term_id', 'hostel_id', 'hostel_building_id',
        'hostel_room_id', 'hostel_bed_id', 'student_id', 'program_id', 'section_id',
    ];

    private const PAGE_FILTERS = [
        'hostels' => ['hostels_page', 'buildings_page'],
        'occupancy' => ['rooms_page', 'beds_page'],
        'allocations' => ['page'],
        'attendance' => ['page'],
        'fees' => ['page'],
        'vacated' => ['page'],
        'summary' => [],
    ];

    public function __construct(private readonly HostelReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HostelReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && array_key_exists($requested, self::REPORTS)
            ? $requested
            : 'hostels';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'occupancy' => $this->reports->occupancy($filters),
            'allocations' => ['allocationReport' => $this->reports->allocations($filters)],
            'attendance' => ['attendanceReport' => $this->reports->attendance($filters)],
            'fees' => ['feeReport' => $this->reports->fees($filters)],
            'vacated' => ['vacatedReport' => $this->reports->vacated($filters)],
            'summary' => $this->reports->summary(),
            default => $this->reports->hostelBuildingReport($filters),
        };

        return view('hostel_reports.index', array_merge($data, [
            'filterOptions' => $this->filterOptions($report, $filters),
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
            'statusOptions' => $this->statusOptions($report),
        ]));
    }

    /** Validate only filters used by the selected report; foreign-tenant IDs
     * remain ordinary integer filters and simply match no tenant-scoped rows.
     */
    private function filters(Request $request, string $report): array
    {
        $visible = self::FILTERS[$report];
        $rules = [];

        foreach (self::ID_FILTERS as $key) {
            if (in_array($key, $visible, true)) {
                $rules[$key] = ['nullable', 'integer', 'min:1'];
            }
        }

        if (in_array('from', $visible, true)) {
            $rules['from'] = ['nullable', 'date_format:Y-m-d'];
            $rules['to'] = [
                'nullable',
                'date_format:Y-m-d',
                ...($request->filled('from') ? ['after_or_equal:from'] : []),
            ];
        }

        if (in_array('allocation_status', $visible, true)) {
            $rules['allocation_status'] = ['nullable', Rule::in(HostelAllocation::STATUSES)];
        }
        if (in_array('attendance_status', $visible, true)) {
            $rules['attendance_status'] = ['nullable', Rule::in(HostelAttendance::STATUSES)];
        }
        if (in_array('fee_status', $visible, true)) {
            $rules['fee_status'] = ['nullable', Rule::in(FeeLedger::STATUSES)];
        }

        foreach (self::PAGE_FILTERS[$report] as $page) {
            $rules[$page] = ['nullable', 'integer', 'min:1', 'max:100000000'];
        }

        $validated = $request->validate($rules);
        $filters = array_fill_keys([
            ...self::ID_FILTERS,
            'from', 'to',
            'allocation_status', 'attendance_status', 'fee_status',
        ], null);

        foreach ($visible as $key) {
            $filters[$key] = $validated[$key] ?? null;
            if (in_array($key, self::ID_FILTERS, true) && $filters[$key] !== null) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }

    /** Tenant-scoped options, loaded only for filters shown by this report. */
    private function filterOptions(string $report, array $filters): array
    {
        $visible = self::FILTERS[$report];
        $has = fn (string $key): bool => in_array($key, $visible, true);
        $options = [];

        if ($has('hostel_id')) {
            $options['hostels'] = Hostel::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code', 'status']);
        }
        if ($has('hostel_building_id')) {
            $options['buildings'] = HostelBuilding::query()
                ->with('hostel:id,name,code')
                ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
                ->orderBy('hostel_id')->orderBy('name')->orderBy('id')
                ->get(['id', 'hostel_id', 'name', 'code', 'status']);
        }
        if ($has('hostel_room_id')) {
            $options['rooms'] = HostelRoom::query()
                ->with(['hostel:id,name,code', 'building:id,name,code'])
                ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
                ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('building_id', $id))
                ->orderBy('hostel_id')->orderBy('building_id')->orderBy('room_number')->orderBy('id')
                ->get(['id', 'hostel_id', 'building_id', 'room_number', 'capacity', 'status']);
        }
        if ($has('hostel_bed_id')) {
            $options['beds'] = HostelBed::query()
                ->with(['hostel:id,name,code', 'building:id,name,code', 'room:id,room_number'])
                ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
                ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('building_id', $id))
                ->when($filters['hostel_room_id'] ?? null, fn (Builder $query, $id) => $query->where('room_id', $id))
                ->orderBy('hostel_id')->orderBy('building_id')->orderBy('room_id')->orderBy('bed_number')->orderBy('id')
                ->get(['id', 'hostel_id', 'building_id', 'room_id', 'bed_number', 'status']);
        }
        if ($has('academic_year_id')) {
            $options['years'] = AcademicYear::query()
                ->orderByDesc('starts_on')->orderByDesc('id')
                ->get(['id', 'name', 'code']);
        }
        if ($has('academic_term_id')) {
            $options['terms'] = AcademicTerm::query()
                ->with('academicYear:id,name,code')
                ->when($filters['academic_year_id'] ?? null, fn (Builder $query, $id) => $query->where('academic_year_id', $id))
                ->orderBy('academic_year_id')->orderBy('sequence')->orderBy('id')
                ->get(['id', 'academic_year_id', 'name', 'code']);
        }
        if ($has('student_id')) {
            $allocationEnrollments = HostelAllocation::query()
                ->when($filters['hostel_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_id', $id))
                ->when($filters['hostel_building_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_building_id', $id))
                ->when($filters['hostel_room_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_room_id', $id))
                ->when($filters['hostel_bed_id'] ?? null, fn (Builder $query, $id) => $query->where('hostel_bed_id', $id))
                ->when($filters['academic_year_id'] ?? null, fn (Builder $query, $id) => $query->where('academic_year_id', $id))
                ->select('student_enrollment_id');
            $studentIds = StudentEnrollment::query()
                ->whereIn('id', $allocationEnrollments)
                ->when($filters['program_id'] ?? null, fn (Builder $query, $id) => $query->where('program_id', $id))
                ->when($filters['section_id'] ?? null, fn (Builder $query, $id) => $query->where('section_id', $id))
                ->select('student_id');
            $options['students'] = Student::query()
                ->whereIn('id', $studentIds)
                ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name']);
        }
        if ($has('program_id')) {
            $options['programs'] = Program::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code']);
        }
        if ($has('section_id')) {
            $options['sections'] = Section::query()
                ->with('program:id,name,code')
                ->when($filters['program_id'] ?? null, fn (Builder $query, $id) => $query->where('program_id', $id))
                ->when($filters['academic_year_id'] ?? null, fn (Builder $query, $id) => $query->where('academic_year_id', $id))
                ->orderBy('program_id')->orderBy('name')->orderBy('id')
                ->get(['id', 'program_id', 'academic_year_id', 'name', 'code']);
        }

        return $options;
    }

    /** @return array<string, array<int, string>> */
    private function statusOptions(string $report): array
    {
        return match ($report) {
            'allocations' => ['allocation_status' => HostelAllocation::STATUSES],
            'attendance' => ['attendance_status' => HostelAttendance::STATUSES],
            'fees' => ['fee_status' => FeeLedger::STATUSES],
            default => [],
        };
    }
}
