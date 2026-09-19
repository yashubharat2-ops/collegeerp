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
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
