<?php

namespace App\Http\Controllers;

use App\Domain\Student\Actions\ConvertApplicationToStudent;
use App\Domain\Student\Services\StudentHistoryService;
use App\Domain\Student\Services\StudentService;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Models\Student;
use App\Services\Files\SecureFileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Student::class);

        // Deterministic creation-order pagination (oldest first) with id tiebreak.
        $query = Student::query()
            ->with(['enrollments.academicYear', 'enrollments.program'])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('student_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('middle_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), Student::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->whereHas('enrollments', fn ($e) => $e->where('academic_year_id', $academicYearId));
        }

        return view('students.index', [
            'students' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
            'academic_year_id' => $request->input('academic_year_id'),
            'academicYears' => \App\Models\AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Student::class);

        return view('students.create');
    }

    public function store(StoreStudentRequest $request, StudentService $service): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();

        $student = $service->createStudent($request->validated(), $collegeId);

        return redirect()->route('students.show', $student)->with('success', 'Student '.$student->student_number.' created.');
    }

    /**
     * The student's 360° profile.
     *
     * This is the Students module's detail page, not a separate "Student
     * Profile" master: every tab is the same data the module pages show,
     * filtered to this student, so there is exactly one entry point.
     */
    public function show(Request $request, string $student, StudentHistoryService $history): View
    {
        $model = $this->findScoped($student);
        $this->authorize('view', $model);

        $model->load([
            'admissionApplication.applicant',
            'admissionApplication.admission',
            'enrollments.academicYear',
            'enrollments.program',
            'enrollments.section',
            'academicRecords.academicYear',
            'academicRecords.academicTerm',
            'academicRecords.program',
            'academicRecords.section',
            'documents.documentType',
            'documents.verifiedBy',
            'promotions.targetAcademicYear',
            'promotions.sourceAcademicYear',
            'promotions.targetSection',
            'transfers.enrollment',
        ]);

        return view('students.show', [
            'student' => $model,
            'tab' => $request->input('tab', 'profile'),
            // Derived, chronological lifecycle timeline (see StudentHistoryService).
            'historyEvents' => $history->forStudent($model),
        ]);
    }

    /**
     * Stream the student's photo from the private disk.
     *
     * The path is stored server-side and re-checked here; a cross-college
     * student 404s through CollegeScope before any file is touched.
     */
    public function photo(string $student, SecureFileService $files): StreamedResponse
    {
        $model = $this->findScoped($student);
        $this->authorize('view', $model);

        $path = (string) $model->photo_path;

        if ($path === ''
            || str_contains($path, '..')
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
        ) {
            abort(404, 'No photo is available for this student.');
        }

        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'No photo is available for this student.');
        }

        return $files->download($path);
    }

    public function edit(string $student): View
    {
        $model = $this->findScoped($student);
        $this->authorize('update', $model);

        return view('students.edit', ['student' => $model]);
    }

    public function update(UpdateStudentRequest $request, string $student, StudentService $service): RedirectResponse
    {
        $model = $this->findScoped($student);

        $service->updateStudent($model, $request->validated());

        return back()->with('success', 'Student updated.');
    }

    public function destroy(string $student, StudentService $service): RedirectResponse
    {
        $model = $this->findScoped($student);
        $this->authorize('delete', $model);

        $service->deleteStudent($model);

        return redirect()->route('students.index')->with('success', 'Student deleted.');
    }

    /**
     * Admission → student conversion: an approved/admitted application becomes
     * an enrolled student via the idempotent transactional action. The action
     * verifies tenant ownership and admission state; the student number and
     * initial enrollment are generated server-side.
     */
    public function convert(string $admission_application, ConvertApplicationToStudent $action): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $collegeId = app(TenantContext::class)->id();

        $student = $action->execute((int) $admission_application, $collegeId);

        return redirect()->route('students.show', $student)->with('success', 'Application converted to student '.$student->student_number.'.');
    }

    /**
     * Tenant-safe lookup: scoped query ensures cross-college 404, not 403 leak.
     */
    private function findScoped(string $id): Student
    {
        return Student::query()->findOrFail($id);
    }
}
