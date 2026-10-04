<?php

namespace App\Http\Controllers;

use App\Domain\Student\Actions\ConvertApplicationToStudent;
use App\Domain\Student\Services\StudentHistoryService;
use App\Domain\Student\Services\StudentListService;
use App\Domain\Student\Services\StudentService;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListContext;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    /**
     * The student list.
     *
     * Search, filters, sorting and pagination are NOT implemented here: they
     * live in StudentListService, which builds the shared ListQueryBuilder, so
     * the screen and the CSV export can never disagree about what "the filtered
     * list" means. The view receives a ListContext (active filters, sort state,
     * clear/sort URLs) plus the tenant-scoped option lists for the filter form.
     */
    public function index(Request $request, StudentListService $list): View
    {
        $this->authorize('viewAny', Student::class);

        // Deterministic creation-order pagination (oldest first) with an id
        // tiebreak, so equal timestamps can never shuffle rows between pages.
        $builder = $list->builder($request)->tiebreaker('students.id');

        $students = $builder->paginate(StudentListService::PER_PAGE);

        return view('students.index', array_merge([
            'students' => $students,
            'listContext' => ListContext::make($builder->getAppliedFilters(), $students, $request, 'students.index'),
            // The bulk export button is only offered to users who may use it;
            // the permission itself is enforced server-side on the endpoint.
            'canExport' => (bool) $request->user()?->hasPermission('students.export'),
        ], $list->filterOptions()));
    }

    /**
     * CSV export of the student list.
     *
     * Two things make this safe and honest:
     *
     *  - It is the SAME filter pipeline as the list (StudentListService), so the
     *    CSV always contains exactly what the active search/filters select — and
     *    an explicit `ids` selection can only ever narrow that set, never widen
     *    it.
     *  - Records are re-queried server-side inside the tenant scope (CollegeScope
     *    on Student); ids from the browser are shape-checked (ListSelection) and
     *    never trusted as records. A foreign college's id simply matches nothing.
     *
     * Permission: `students.export` on top of `students.view` (viewAny), so a
     * read-only user cannot extract the data set they may browse.
     *
     * Rows stream in primary-key order: CsvStreamExport pages with chunkById()
     * for constant memory, which is exactly what a keyset over the id column
     * needs — so the export honours every filter but not the on-screen sort
     * order, which would otherwise make the chunked keyset skip rows.
     */
    public function export(Request $request, StudentListService $list, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', Student::class);
        abort_unless($request->user()?->hasPermission('students.export'), 403);

        $builder = $list->builder($request);
        $query = $builder->getQuery();

        // An explicit selection (the bulk "Export students" action) narrows the
        // export to those students — after the same filters have been applied.
        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('students.id', $ids);
        }

        $audit->record('students.exported', null, [], [
            'filters' => $builder->getAppliedFilters(),
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('students-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Student number', 'First name', 'Middle name', 'Last name', 'Email', 'Mobile', 'Alternate mobile',
                'Gender', 'Category', 'Student status', 'Admission date', 'Date of birth',
                'Enrollment number', 'Academic year', 'Program', 'Department', 'Section', 'Enrollment status',
            ])
            ->map(function (Student $student): array {
                $enrollment = $student->currentEnrollment();

                return [
                    $student->student_number,
                    $student->first_name,
                    $student->middle_name,
                    $student->last_name,
                    $student->email,
                    $student->phone,
                    $student->alternate_phone,
                    $student->gender,
                    $student->category,
                    $student->status,
                    $student->admission_date?->format('Y-m-d'),
                    $student->date_of_birth?->format('Y-m-d'),
                    $enrollment?->enrollment_number,
                    $enrollment?->academicYear?->name,
                    $enrollment?->program?->name,
                    $enrollment?->program?->department?->name,
                    $enrollment?->section?->name,
                    $enrollment?->status,
                ];
            })
            ->streamFromQuery($query);
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
