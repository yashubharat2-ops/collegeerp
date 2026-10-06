<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentService;
use App\Http\Requests\StudentEnrollment\StoreStudentEnrollmentRequest;
use App\Http\Requests\StudentEnrollment\UpdateStudentEnrollmentRequest;
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
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentEnrollment::class);

        // Deterministic creation-order pagination (oldest first) with id tiebreak.
        $query = StudentEnrollment::query()
            ->with(['student', 'academicYear', 'program', 'section'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($request->input('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->input('academic_year_id')) {
            $query->where('academic_year_id', $request->input('academic_year_id'));
        }

        if (in_array($request->input('status'), StudentEnrollment::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('student_enrollments.index', [
            'enrollments' => $query->paginate(15)->withQueryString(),
            'student_id' => $request->input('student_id'),
            'academic_year_id' => $request->input('academic_year_id'),
            'status' => $request->input('status'),
            'students' => Student::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'student_number', 'first_name', 'last_name']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * CSV of enrollments, optionally narrowed to an authorized id selection.
     * Identity numbers (Aadhaar, government ID) are never exported.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', StudentEnrollment::class);

        $query = StudentEnrollment::query()->with(['student', 'academicYear', 'program', 'section']);

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('student_enrollments.id', $ids);
        }

        $audit->record('student_enrollments.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('enrollments-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Enrollment number', 'Student number', 'Student', 'Academic year',
                'Program', 'Section', 'Enrollment date', 'Status', 'Remarks',
            ])
            ->map(function (StudentEnrollment $enrollment): array {
                return [
                    $enrollment->enrollment_number,
                    $enrollment->student?->student_number,
                    trim(($enrollment->student?->first_name ?? '').' '.($enrollment->student?->last_name ?? '')),
                    $enrollment->academicYear?->name,
                    $enrollment->program?->name,
                    $enrollment->section?->name,
                    $enrollment->enrollment_date?->format('Y-m-d'),
                    $enrollment->status,
                    $enrollment->remarks,
                ];
            })
            ->streamFromQuery($query);
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

    public function create(Request $request): View
    {
        $this->authorize('create', StudentEnrollment::class);

        return view('student_enrollments.create', [
            'students' => Student::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'student_number', 'first_name', 'last_name']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => $this->sectionOptions(),
            'selectedStudentId' => $request->input('student_id'),
        ]);
    }

    public function store(StoreStudentEnrollmentRequest $request, StudentService $service): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();

        $enrollment = $service->createEnrollment($request->validated(), $collegeId, audit: true);

        return redirect()->route('student-enrollments.index')->with('success', 'Enrollment '.$enrollment->enrollment_number.' created.');
    }

    public function edit(string $student_enrollment): View
    {
        $model = $this->findScoped($student_enrollment);
        $this->authorize('update', $model);

        return view('student_enrollments.edit', [
            'enrollment' => $model->load(['student', 'academicYear', 'program', 'section']),
            'sections' => $this->sectionOptions(),
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
