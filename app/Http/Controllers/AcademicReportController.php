<?php

namespace App\Http\Controllers;

use App\Domain\Academic\Services\AcademicReportService;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicReport;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultySubjectAssignment;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Read-only Academic Reports, separate from the operational Academics pages. */
class AcademicReportController extends Controller
{
    public const REPORTS = [
        'subject_enrollments' => 'Enrollment / Subject Enrollment Report',
        'section_strength' => 'Class / Section Strength Report',
        'subject_students' => 'Subject-wise Student Report',
        'faculty_subjects' => 'Faculty-wise Subject Report',
        'timetable' => 'Timetable Report',
        'attendance' => 'Attendance Report',
        'student_attendance' => 'Student Attendance Summary',
        'workload' => 'Faculty Workload Report',
        'calendar' => 'Academic Calendar Report',
    ];

    /** Filters that apply to each report; any other query parameter is ignored. */
    public const FILTERS = [
        'subject_enrollments' => ['search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'status', 'from', 'to'],
        'section_strength' => ['academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'status'],
        'subject_students' => ['academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'status'],
        'faculty_subjects' => ['search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'status'],
        'timetable' => ['academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'day_of_week', 'status'],
        'attendance' => ['search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'attendance_status', 'from', 'to'],
        'student_attendance' => ['search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'group', 'below', 'from', 'to'],
        'workload' => ['academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id'],
        'calendar' => ['search', 'academic_year_id', 'academic_term_id', 'event_type', 'status', 'from', 'to'],
    ];

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private const KEYS = [
        'search', 'academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id',
        'subject_id', 'faculty_id', 'status', 'attendance_status', 'day_of_week', 'event_type', 'group', 'below', 'from', 'to',
    ];

    public function index(Request $request, AcademicReportService $reports): View
    {
        $this->authorize('viewAny', AcademicReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'subject_enrollments';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'section_strength' => $reports->sectionStrength($filters),
            'subject_students' => $reports->subjectStudents($filters),
            'faculty_subjects' => $reports->facultySubjects($filters),
            'timetable' => $reports->timetable($filters),
            'attendance' => $reports->attendance($filters),
            'student_attendance' => $reports->studentAttendance($filters),
            'workload' => $reports->workload($filters),
            'calendar' => $reports->calendar($filters),
            default => $reports->subjectEnrollments($filters),
        };

        return view('academic_reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'days' => self::DAYS,
        ]));
    }

    /** Record statuses selectable per report (empty = no status filter). */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'subject_enrollments', 'subject_students' => AcademicReportService::SUBJECT_ENROLLMENT_STATUSES,
            'section_strength' => Section::STATUSES,
            'faculty_subjects' => FacultySubjectAssignment::STATUSES,
            'timetable' => AcademicReportService::TIMETABLE_STATUSES,
            'calendar' => AcademicReportService::CALENDAR_STATUSES,
            default => [],
        };
    }

    private function filters(Request $request, string $report): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'academic_term_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'section_id' => ['nullable', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'faculty_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in([...self::statuses($report), 'all'])],
            'attendance_status' => ['nullable', Rule::in(AcademicReportService::ATTENDANCE_STATUSES)],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'event_type' => ['nullable', 'string', 'max:50'],
            'group' => ['nullable', Rule::in(['subject', 'student'])],
            'below' => ['nullable', 'numeric', 'between:0,100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Only the filters of the selected report take effect; the service
        // always receives the same keys. An integer ID from another college
        // matches nothing, because every root query is tenant-scoped.
        $filters = array_fill_keys(self::KEYS, null);
        foreach (self::FILTERS[$report] as $key) {
            $filters[$key] = $validated[$key] ?? null;
        }
        $filters['search'] = trim((string) ($filters['search'] ?? ''));
        foreach (['academic_year_id', 'academic_term_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'day_of_week'] as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        $filters['event_type'] = filled($filters['event_type']) ? trim($filters['event_type']) : null;

        // The timetable shows active entries unless another status (or "all")
        // is chosen explicitly. Elsewhere an empty status means all statuses.
        if ($report === 'timetable') {
            $filters['status'] ??= 'active';
        }
        $filters['status_choice'] = $filters['status'];
        if ($filters['status'] === 'all') {
            $filters['status'] = null;
        }
        if ($report === 'student_attendance') {
            $filters['group'] ??= 'subject';
        }

        return $filters;
    }

    /** Tenant-scoped dropdown options, loaded only for the filters shown. */
    private function options(string $report): array
    {
        $visible = array_flip(self::FILTERS[$report]);
        $options = [];

        if (isset($visible['academic_year_id'])) {
            $options['academicYears'] = AcademicYear::query()->orderByDesc('starts_on')->orderByDesc('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['academic_term_id'])) {
            $options['academicTerms'] = AcademicTerm::query()->with('academicYear:id,name')
                ->orderBy('academic_year_id')->orderBy('sequence')->orderBy('id')->get(['id', 'name', 'code', 'academic_year_id']);
        }
        if (isset($visible['department_id'])) {
            $options['departments'] = Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);
        }
        if (isset($visible['program_id'])) {
            $options['programs'] = Program::query()->with('department:id,name')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'department_id']);
        }
        if (isset($visible['section_id'])) {
            $options['sections'] = Section::query()->with(['academicYear:id,name', 'program:id,name'])
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']);
        }
        if (isset($visible['subject_id'])) {
            $options['subjects'] = Subject::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code']);
        }
        if (isset($visible['faculty_id'])) {
            $options['faculties'] = Faculty::query()->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
        }
        if (isset($visible['event_type'])) {
            $options['eventTypes'] = AcademicCalendarEvent::query()->distinct()->orderBy('event_type')->pluck('event_type');
        }

        return $options;
    }
}
