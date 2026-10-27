<?php

namespace App\Http\Controllers;

use App\Domain\Student\Filters\StudentFilter;
use App\Domain\Student\Services\EnrollmentApprovalService;
use App\Domain\Student\Services\StudentService;
use App\Http\Requests\StudentEnrollment\StoreStudentEnrollmentRequest;
use App\Http\Requests\StudentEnrollment\UpdateStudentEnrollmentRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentEnrollmentController extends Controller
{
    public function index(Request $request, StudentFilter $studentFilter): View
    {
        $this->authorize('viewAny', StudentEnrollment::class);
        $filters = $this->filters($request, $studentFilter);

        // Deterministic creation-order pagination (oldest first) with id tiebreak.
        $query = StudentEnrollment::query()->with(['student', 'academicYear', 'academicTerm', 'program', 'section']);
        $studentFilter->applyToEnrollments($query, $filters);
        $query->when($request->input('student_id'), fn ($q, $value) => $q->where('student_id', $value))
            ->orderBy('created_at')->orderBy('id');

        return view('student_enrollments.index', array_merge(
            $studentFilter->viewData($filters, StudentFilter::STUDENT_LIST_FIELDS, $request->user()),
            [
                'enrollments' => $query->paginate(15)->withQueryString(),
                'student_id' => $request->input('student_id'),
                'academic_year_id' => $request->input('academic_year_id'),
                'status' => $request->input('status'),
                'students' => Student::query()->approvedForOperations()->orderBy('first_name')->orderBy('last_name')->get(['id', 'student_number', 'first_name', 'last_name']),
                'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
                'sections' => $this->sectionOptions(),
            ],
        ));
    }

    /**
     * CSV of enrollments, optionally narrowed to an authorized id selection.
     * Identity numbers (Aadhaar, government ID) are never exported.
     */
    public function export(Request $request, AuditLogService $audit, StudentFilter $studentFilter): StreamedResponse
    {
        $this->authorize('viewAny', StudentEnrollment::class);
        $filters = $this->filters($request, $studentFilter);
        $query = StudentEnrollment::query()->with(['student', 'academicYear', 'academicTerm', 'program', 'section']);
        $studentFilter->applyToEnrollments($query, $filters);
        $query->when($request->input('student_id'), fn ($q, $value) => $q->where('student_id', $value));

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('student_enrollments.id', $ids);
        }

        $audit->record('student_enrollments.exported', null, [], [
            'selected_ids' => count($ids),
            'filters' => $studentFilter->auditSafe($filters),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('enrollments-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Enrollment number', 'Student number', 'Student', 'Academic year',
                'Semester / year', 'Program', 'Section', 'Enrollment date', 'Roll number', 'Status', 'Remarks',
            ])
            ->map(function (StudentEnrollment $enrollment): array {
                return [
                    $enrollment->enrollment_number,
                    $enrollment->student?->student_number,
                    trim(($enrollment->student?->first_name ?? '').' '.($enrollment->student?->last_name ?? '')),
                    $enrollment->academicYear?->name,
                    // The configured level when there is one, else the stored
                    // label — the same value the list and the profile print.
                    $enrollment->semesterLabel(),
                    $enrollment->program?->name,
                    $enrollment->section?->name,
                    $enrollment->enrollment_date?->format('Y-m-d'),
                    $enrollment->roll_number,
                    $enrollment->status,
                    $enrollment->remarks,
                ];
            })
            ->streamFromQuery($query);
    }

    /** Preserve the legacy enrollment `status` alias without confusing it with Student.status. */
    private function filters(Request $request, StudentFilter $studentFilter): array
    {
        $filters = $studentFilter->fromRequest($request, user: $request->user());
        if (! isset($filters['enrollment_status']) && in_array($request->input('status'), StudentEnrollment::STATUSES, true)) {
            $filters['enrollment_status'] = $request->input('status');
        }

        return $filters;
    }

    /**
     * Section options with their year/program context. Sections are Platform
     * master data and are referenced, never duplicated; the Form Request and
     * StudentService both re-validate that a chosen section belongs to the
     * enrollment's academic year and program in this college.
     */
    private function sectionOptions()
    {
        return Section::query()
            ->with(['academicYear:id,name', 'program:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'academic_year_id', 'program_id']);
    }

    /**
     * The New Enrollment form.
     *
     * Only ELIGIBLE students are offered — `approvedForOperations()` = an approved
     * registration or a legacy student — which is the same rule StudentService
     * enforces on the write path, so the picker cannot show a student the server
     * would then refuse.
     *
     * Each option is identified by Student ID, name and (when the student came
     * through an admission) the Admission Number. Staff select an existing student
     * here; they never re-enter the registration form. The Admission Number is
     * resolved through the student's own admission application, and is shown as
     * "no admission" when the student never went through that workflow rather than
     * inventing a number.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', StudentEnrollment::class);

        $collegeId = (int) app(TenantContext::class)->id();

        return view('student_enrollments.create', [
            'students' => Student::query()
                ->approvedForOperations()
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'admission_application_id']),
            'admissionNumbers' => $this->admissionNumbers(),
            'approvalRequired' => app(EnrollmentApprovalService::class)->isRequired($collegeId),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            // Configured semester / year of study, per academic year. The form
            // narrows the list to the chosen year; StudentService re-validates
            // the combination, so the browser is never the authority.
            'terms' => AcademicTerm::query()
                ->where('status', 'active')
                ->orderBy('academic_year_id')->orderBy('sequence')->orderBy('name')
                ->get(['id', 'academic_year_id', 'name', 'code', 'type']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => $this->sectionOptions(),
            'selectedStudentId' => $request->input('student_id'),
        ]);
    }

    /**
     * Admission Number per student, for the picker and the edit screen.
     *
     * A student records the admission APPLICATION they were converted from; the
     * Admission Number lives on the admission of that application. Students who
     * never went through an admission (direct registration, import, transfer) have
     * none — the map simply has no entry, and the UI says so instead of printing a
     * placeholder that looks like an identifier.
     *
     * @return array<int, string>
     */
    private function admissionNumbers(): array
    {
        $collegeId = (int) app(TenantContext::class)->id();

        return \App\Models\Admission::withoutGlobalScopes()
            ->join('admission_applications', 'admission_applications.id', '=', 'admissions.application_id')
            ->join('students', 'students.admission_application_id', '=', 'admission_applications.id')
            ->where('admissions.college_id', $collegeId)
            ->where('students.college_id', $collegeId)
            ->whereNull('students.deleted_at')
            ->pluck('admissions.admission_number', 'students.id')
            ->all();
    }

    /**
     * Create an enrollment for an ELIGIBLE student.
     *
     * The write goes through EnrollmentApprovalService, which applies the
     * college's enrollment-approval setting: when it is on, the row is created as
     * `pending_approval` and an `student.enrollment_approval` request is raised in
     * the same transaction, so the enrollment only becomes active by decision in
     * Approval Management. When it is off (the default, like every module except
     * admission and student registration) the enrollment is active immediately.
     */
    public function store(
        StoreStudentEnrollmentRequest $request,
        EnrollmentApprovalService $enrollments,
    ): RedirectResponse {
        $collegeId = (int) app(TenantContext::class)->id();

        $result = $enrollments->create(
            $request->validated(),
            $collegeId,
            (int) $request->user()->getKey(),
        );

        $enrollment = $result['enrollment'];

        return redirect()->route('student-enrollments.index')->with(
            'success',
            $result['pending']
                ? 'Enrollment '.$enrollment->enrollment_number.' created and submitted for approval. It is not active until it is approved.'
                : 'Enrollment '.$enrollment->enrollment_number.' created.',
        );
    }

    public function edit(string $student_enrollment): View
    {
        $model = $this->findScoped($student_enrollment);
        $this->authorize('update', $model);

        return view('student_enrollments.edit', [
            'enrollment' => $model->load(['student', 'academicYear', 'academicTerm', 'program', 'section']),
            'admissionNumbers' => $this->admissionNumbers(),
            'sections' => $this->sectionOptions(),
            // The academic year of an enrollment is immutable, so only the
            // levels of that year can be offered.
            'terms' => AcademicTerm::query()
                ->where('academic_year_id', $model->academic_year_id)
                ->where('status', 'active')
                ->orderBy('sequence')->orderBy('name')
                ->get(['id', 'academic_year_id', 'name', 'code', 'type']),
        ]);
    }

    public function update(UpdateStudentEnrollmentRequest $request, string $student_enrollment, StudentService $service): RedirectResponse
    {
        $model = $this->findScoped($student_enrollment);
        $collegeId = app(TenantContext::class)->id();

        $service->updateEnrollment($model, $request->validated(), $collegeId);

        return back()->with('success', 'Enrollment updated.');
    }

    public function destroy(string $student_enrollment, StudentService $service): RedirectResponse
    {
        $model = $this->findScoped($student_enrollment);
        $this->authorize('delete', $model);

        $service->deleteEnrollment($model);

        return redirect()->route('student-enrollments.index')->with('success', 'Enrollment deleted.');
    }

    private function findScoped(string $id): StudentEnrollment
    {
        return StudentEnrollment::query()->findOrFail($id);
    }
}
