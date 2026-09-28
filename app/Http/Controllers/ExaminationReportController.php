<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Examination;
use App\Models\ExaminationReport;
use App\Models\ExamAttendance;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use App\Services\Examinations\ExaminationReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Examination Reports — a read-only reporting layer over the existing
 * Examinations module (Examinations / Exam Schedule / Exam Attendance /
 * Marks Entry / Results / Result Publishing) and its derived documents
 * (Marksheets, Grade Cards, Student Result History).
 *
 * Nothing is persisted here: no report tables, no calculation, no grading
 * logic — every figure is read live from the existing models through
 * ExaminationReportService, always tenant-scoped by CollegeScope.
 */
class ExaminationReportController extends Controller
{
    public const REPORTS = [
        'summary' => 'Examination Summary',
        'schedule' => 'Exam Schedule Report',
        'attendance' => 'Exam Attendance Report',
        'marks' => 'Marks / Marks Entry Report',
        'subject_results' => 'Subject-wise Result Report',
        'student_results' => 'Student Result Report',
        'pass_fail' => 'Pass / Fail Report',
        'grades' => 'Grade-wise Report',
        'merit' => 'Merit / Rank Report',
        'publishing' => 'Result Publishing Report',
        'marksheet' => 'Marksheet Report',
        'grade_card' => 'Grade Card Report',
        'result_history' => 'Student Result History',
    ];

    /** Filters that apply to each report; any other query parameter is ignored. */
    public const FILTERS = [
        'summary' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'status', 'from', 'to'],
        'schedule' => ['academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'status', 'from', 'to'],
        'attendance' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'attendance_status', 'from', 'to'],
        'marks' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'mark_status', 'from', 'to'],
        'subject_results' => ['academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id', 'result_status'],
        'student_results' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'result_status', 'grade'],
        'pass_fail' => ['academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id'],
        'grades' => ['academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'grade'],
        'merit' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'result_status'],
        'publishing' => ['academic_year_id', 'academic_term_id', 'examination_id', 'status', 'from', 'to'],
        'marksheet' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'result_status', 'grade', 'from', 'to'],
        'grade_card' => ['search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'result_status', 'grade', 'from', 'to'],
        'result_history' => ['search', 'academic_year_id', 'department_id', 'program_id', 'section_id', 'result_status'],
    ];

    /** Date-range meaning per report (for labels in the filter form). */
    public const DATE_LABELS = [
        'summary' => 'Examination date',
        'schedule' => 'Exam date',
        'attendance' => 'Exam date',
        'marks' => 'Exam date',
        'publishing' => 'Examination date',
        'marksheet' => 'Published date',
        'grade_card' => 'Published date',
    ];

    private const KEYS = [
        'search', 'academic_year_id', 'academic_term_id', 'examination_id', 'department_id',
        'program_id', 'section_id', 'subject_id', 'faculty_id', 'status', 'attendance_status',
        'mark_status', 'result_status', 'grade', 'from', 'to',
    ];

    public function index(Request $request, ExaminationReportService $reports): View
    {
        $this->authorize('viewAny', ExaminationReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'summary';
        $filters = $this->filters($request, $report);

        $data = match ($report) {
            'schedule' => $reports->schedule($filters),
            'attendance' => $reports->attendance($filters),
            'marks' => $reports->marks($filters),
            'subject_results' => $reports->subjectResults($filters),
            'student_results' => $reports->studentResults($filters),
            'pass_fail' => $reports->passFail($filters),
            'grades' => $reports->grades($filters),
            'merit' => $reports->merit($filters),
            'publishing' => $reports->publishing($filters),
            'marksheet' => $reports->marksheet($filters),
            'grade_card' => $reports->gradeCard($filters),
            'result_history' => $reports->resultHistory($filters),
            default => $reports->summary($filters),
        };

        return view('examination_reports.index', array_merge($data, $this->options($report), [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'visible' => self::FILTERS[$report],
            'statuses' => self::statuses($report),
            'statusKey' => self::statusKey($report),
            'dateLabel' => self::DATE_LABELS[$report] ?? 'Date',
        ]));
    }

    /** Record statuses selectable per report (empty = no status filter). */
    public static function statuses(string $report): array
    {
        return match ($report) {
            'summary', 'publishing' => Examination::STATUSES,
            'schedule' => ExamSchedule::STATUSES,
            'attendance' => ExamAttendance::STATUSES,
            'marks' => ExamMark::STATUSES,
            'subject_results', 'student_results', 'merit', 'marksheet', 'grade_card', 'result_history' => ExamResult::RESULT_STATUSES,
            default => [],
        };
    }

    /** The status query-parameter name the report uses (null = none). */
    public static function statusKey(string $report): ?string
    {
        return match ($report) {
            'summary', 'publishing', 'schedule' => 'status',
            'attendance' => 'attendance_status',
            'marks' => 'mark_status',
            'subject_results', 'student_results', 'merit', 'marksheet', 'grade_card', 'result_history' => 'result_status',
            default => null,
        };
    }

    private function filters(Request $request, string $report): array
    {
        $statusKey = self::statusKey($report);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'academic_term_id' => ['nullable', 'integer', 'min:1'],
            'examination_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'section_id' => ['nullable', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'faculty_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', $statusKey === 'status' ? Rule::in(self::statuses($report)) : 'string'],
            'attendance_status' => ['nullable', $statusKey === 'attendance_status' ? Rule::in(self::statuses($report)) : 'string'],
            'mark_status' => ['nullable', $statusKey === 'mark_status' ? Rule::in(self::statuses($report)) : 'string'],
            'result_status' => ['nullable', $statusKey === 'result_status' ? Rule::in(self::statuses($report)) : 'string'],
            'grade' => ['nullable', 'string', 'max:20'],
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
        foreach (['academic_year_id', 'academic_term_id', 'examination_id', 'department_id', 'program_id', 'section_id', 'subject_id', 'faculty_id'] as $key) {
            $filters[$key] = $filters[$key] === null ? null : (int) $filters[$key];
        }
        foreach (['status', 'attendance_status', 'mark_status', 'result_status', 'grade'] as $key) {
            $filters[$key] = filled($filters[$key]) ? trim((string) $filters[$key]) : null;
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
        if (isset($visible['examination_id'])) {
            $options['examinations'] = Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']);
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
        if (isset($visible['grade'])) {
            $options['grades'] = ExamResult::query()->whereNotNull('published_at')->whereNotNull('overall_grade')
                ->distinct()->orderBy('overall_grade')->pluck('overall_grade');
        }

        return $options;
    }
}
