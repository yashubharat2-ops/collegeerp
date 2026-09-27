<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentHistoryService;
use App\Domain\Student\Services\StudentReportService;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use App\Models\StudentReport;
use App\Models\StudentTransfer;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Read-only Student Reports, separate from the operational Students pages. */
class StudentReportController extends Controller
{
    public const REPORTS = [
        'students' => 'Student List',
        'profile' => 'Student Profile / Detail',
        'admissions' => 'Admission Report',
        'enrollments' => 'Enrollment Report',
        'strength' => 'Student Strength Report',
        'new_old' => 'New / Old Student Report',
        'demographics' => 'Category / Gender / Caste Report',
        'documents' => 'Student Documents Status Report',
        'promotions' => 'Promotion Report',
        'transfers' => 'Transfer / TC Report',
        'history' => 'Student History Report',
    ];

    public function index(Request $request, StudentReportService $reports): View
    {
        $this->authorize('viewAny', StudentReport::class);

        $requested = $request->query('report');
        $report = is_string($requested) && isset(self::REPORTS[$requested]) ? $requested : 'students';
        $filters = $this->filters($request);

        // Strength answers "how many active students are enrolled now?" by
        // default. Both statuses may be changed explicitly (including "all").
        if ($report === 'strength') {
            $filters['student_status'] ??= 'active';
            $filters['enrollment_status'] ??= 'active';
        }

        $data = match ($report) {
            'admissions' => ['rows' => $reports->admissions($filters)],
            'enrollments' => ['rows' => $reports->enrollments($filters)],
            'strength' => $reports->strength($filters),
            'new_old' => $reports->newOld($filters),
            'demographics' => $reports->demographics($filters),
            'documents' => ['rows' => $reports->documents($filters)],
            'promotions' => ['rows' => $reports->promotions($filters)],
            'transfers' => ['rows' => $reports->transfers($filters)],
            'profile', 'history' => ['rows' => $reports->roster($filters)],
            default => ['rows' => $reports->roster($filters, true)],
        };

        return view('student_reports.index', array_merge($data, [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $filters,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->orderByDesc('id')->get(['id', 'name', 'code']),
            'programs' => Program::query()->with('department:id,name')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'department_id']),
            'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'sections' => Section::query()->with(['academicYear:id,name', 'program:id,name'])
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
        ]));
    }

    public function profile(string $student): View
    {
        $this->authorize('viewAny', StudentReport::class);

        // Resolve via Student's CollegeScope BEFORE loading any relationships.
        $model = Student::query()->with([
            'admissionApplication.applicant', 'admissionApplication.academicYear', 'admissionApplication.program.department',
            'admissionApplication.admission.academicYear', 'admissionApplication.admission.program.department',
            'enrollments.academicYear', 'enrollments.program.department', 'enrollments.section',
            'academicRecords.academicYear', 'academicRecords.academicTerm',
            'academicRecords.program', 'academicRecords.section',
            'documents.documentType',
            'promotions.sourceAcademicYear', 'promotions.sourceProgram', 'promotions.sourceSection',
            'promotions.targetAcademicYear', 'promotions.targetProgram', 'promotions.targetSection',
            'promotions.targetEnrollment',
            'transfers.enrollment.academicYear', 'transfers.enrollment.program',
        ])->findOrFail($student);

        return view('student_reports.profile', ['student' => $model, 'reports' => self::REPORTS]);
    }

    public function history(Request $request, string $student, StudentHistoryService $history): View
    {
        $this->authorize('viewAny', StudentReport::class);

        $filters = $request->validate([
            'category' => ['nullable', Rule::in(['admission', 'student', 'enrollment', 'academic', 'promotion', 'transfer', 'document', 'audit'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $model = Student::query()->findOrFail($student);

        // The existing lifecycle service owns event derivation and ordering.
        // Filter its deterministic timeline; paginate rendering for long lives.
        $events = $history->forStudent($model)->filter(function ($event) use ($filters): bool {
            $date = $event->occurredAt->toDateString();

            return (! isset($filters['category']) || $event->category === $filters['category'])
                && (! isset($filters['from']) || $date >= $filters['from'])
                && (! isset($filters['to']) || $date <= $filters['to']);
        })->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('student_reports.history', [
            'student' => $model,
            'events' => new LengthAwarePaginator($events->forPage($page, 25)->values(), $events->count(), 25, $page, [
                'path' => $request->url(), 'query' => $request->query(),
            ]),
            'filters' => $filters,
            'reports' => self::REPORTS,
        ]);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'academic_year_id' => ['nullable', 'integer', 'min:1'],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'section_id' => ['nullable', 'integer', 'min:1'],
            'student_status' => ['nullable', Rule::in([...Student::STATUSES, 'all'])],
            'enrollment_status' => ['nullable', Rule::in([...StudentEnrollment::STATUSES, 'all'])],
            'admission_status' => ['nullable', Rule::in(Admission::STATUSES)],
            'promotion_status' => ['nullable', Rule::in(StudentPromotion::STATUSES)],
            'transfer_status' => ['nullable', Rule::in(StudentTransfer::STATUSES)],
            'tc_status' => ['nullable', Rule::in(StudentTransfer::TC_STATUSES)],
            'document_status' => ['nullable', Rule::in([...StudentDocument::VERIFICATION_STATUSES, 'none'])],
            'entry_type' => ['nullable', Rule::in(['new', 'old'])],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say', 'not_recorded'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // Consistent keys for the service and the filter form. An integer ID
        // outside this tenant simply yields no rows through scoped whereHas.
        $filters = array_fill_keys([
            'academic_year_id', 'program_id', 'department_id', 'section_id', 'student_status',
            'enrollment_status', 'admission_status', 'promotion_status', 'transfer_status',
            'tc_status', 'document_status', 'entry_type', 'gender', 'from', 'to',
        ], null);

        return array_replace($filters, $validated, ['search' => trim((string) ($validated['search'] ?? ''))]);
    }
}
