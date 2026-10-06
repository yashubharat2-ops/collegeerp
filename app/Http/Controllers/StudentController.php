<?php

namespace App\Http\Controllers;

use App\Domain\Student\Actions\ConvertAdmissionToStudent;
use App\Domain\Student\Actions\ConvertApplicationToStudent;
use App\Domain\Student\Services\StudentHistoryService;
use App\Domain\Student\Services\StudentListService;
use App\Domain\Student\Services\StudentService;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
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

        // An explicit selection (the bulk Export menu's Excel option) narrows the
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

    /**
     * Upper bound on one printed / PDF report.
     *
     * The CSV export streams with chunkById() and is unbounded on purpose. A
     * report is rendered as ONE document, so it cannot be streamed: the cap keeps
     * a multi-thousand-row college from turning a print click into an out-of-memory
     * 500. The view states honestly when the cap is hit and points at the Excel
     * export for the complete set.
     */
    public const REPORT_ROW_LIMIT = 1000;

    /**
     * The student list as a printable A4 report — the target of the export
     * dropdown's "PDF" option (review, then save as PDF from the dialog).
     *
     * Same filter pipeline, same tenant scope and same permission as the CSV
     * export; see exportReport().
     */
    public function exportPdf(Request $request, StudentListService $list, AuditLogService $audit): View
    {
        return $this->exportReport($request, $list, $audit, 'pdf');
    }

    /**
     * The same A4 report, opened with the browser's print dialog.
     *
     * There is no PDF library in this project (and no need to add one): the
     * established mechanism for official documents — receipts, ID card batches —
     * is a print-safe HTML page plus the browser's native print / "Save as PDF"
     * dialog, styled by the `@media print` rules in resources/css/app.css. A PDF
     * and a print are therefore the same server-rendered document; only the
     * dialog differs, and the printed/saved file never depends on a client-side
     * library or a headless browser.
     */
    public function exportPrint(Request $request, StudentListService $list, AuditLogService $audit): View
    {
        return $this->exportReport($request, $list, $audit, 'print');
    }

    /**
     * Build the printable student report for the current query string.
     *
     * Honest about its inputs, exactly like the CSV export:
     *
     *  - the same StudentListService/ListQueryBuilder pipeline as the list, so the
     *    report can only ever contain what the filters select;
     *  - an explicit `ids` selection (the bulk bar's PDF/Print options) only ever
     *    NARROWS that set: the ids are shape-checked (ListSelection), re-queried
     *    inside the tenant scope, and each surviving record is re-authorized
     *    through the Student policy — a foreign college's student and a
     *    soft-deleted student match nothing;
     *  - `students.export` on top of `students.view`, so the report is behind the
     *    same permission as the CSV (the bulk path checks it twice: once in the
     *    handler, once here).
     *
     * Unlike the CSV stream — which pages by primary key to stay constant-memory
     * and therefore cannot honour the on-screen order — the report is rendered in
     * a single pass, so it DOES honour the selected sort plus a stable id
     * tiebreak, and it is capped at REPORT_ROW_LIMIT rows.
     */
    private function exportReport(Request $request, StudentListService $list, AuditLogService $audit, string $format): View
    {
        $this->authorize('viewAny', Student::class);
        abort_unless($request->user()?->hasPermission('students.export'), 403);

        $builder = $list->builder($request);
        $query = $builder->getQuery();

        // An explicit selection narrows the report, after the same filters have
        // been applied — never widens it.
        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('students.id', $ids);
        }

        $total = (clone $query)->count();

        $rows = $builder->applySorts()
            ->tiebreaker('students.id')
            ->getQuery()
            ->limit(self::REPORT_ROW_LIMIT + 1)
            ->get();

        // One extra row was fetched purely to detect the cap, never to print it.
        $truncated = $rows->count() > self::REPORT_ROW_LIMIT;
        $rows = $rows->take(self::REPORT_ROW_LIMIT);

        // Per-record authorization, as on every printable document in this
        // module: a record the viewer may not read is skipped and reported.
        $students = $rows
            ->filter(fn (Student $student) => $request->user()?->can('view', $student) ?? false)
            ->values();

        $audit->record('students.exported', null, [], [
            'format' => $format,
            'filters' => $builder->getAppliedFilters(),
            'selected_ids' => count($ids),
            'rows' => $students->count(),
        ]);

        return view('students.export_report', [
            'students' => $students,
            'college' => app(TenantContext::class)->college(),
            'total' => $total,
            'truncated' => $truncated,
            'limit' => self::REPORT_ROW_LIMIT,
            'isSelection' => $ids !== [],
            'skipped' => $rows->count() - $students->count(),
            'autoPrint' => $format === 'print',
            'generatedAt' => now(),
        ]);
    }

    /**
     * The Create/Edit form.
     *
     * The controller only loads what the form cannot derive: the academic
     * option lists for the OPTIONAL first enrollment. They are loaded only for
     * a user who may create enrollments (the same `StudentEnrollment` policy the
     * Enrollment module uses), and the Form Request strips the corresponding
     * fields for anyone else — so the Student policy is never a way around the
     * Enrollment policy.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Student::class);

        return view('students.create', $this->profileFormOptions($request));
    }

    /**
     * Existing Student Create form in "From Admission" mode.
     *
     * Compatible applicant/admission fields are flashed as old input so the
     * six-section form prefills without a second form or a design change.
     */
    public function createFromAdmission(Request $request, string $admission): View|RedirectResponse
    {
        $this->authorize('create', Student::class);

        $model = Admission::query()->with(['applicant', 'application', 'academicYear', 'program', 'student.enrollments'])->findOrFail($admission);

        if ($model->status === 'cancelled') {
            return redirect()
                ->route('admissions.index')
                ->withErrors(['admission' => 'A cancelled admission cannot be converted to a student.']);
        }

        if ($model->student) {
            return redirect()
                ->route('students.show', $model->student)
                ->with('success', 'This admission has already been converted to student '.$model->student->student_number.'.');
        }

        if (! $request->session()->hasOldInput()) {
            $request->session()->now('_old_input', $this->defaultsFromAdmission($model));
        }

        return view('students.create', array_merge($this->profileFormOptions($request), [
            'fromAdmission' => $model,
        ]));
    }

    public function storeFromAdmission(StoreStudentRequest $request, string $admission, ConvertAdmissionToStudent $action): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();
        $model = Admission::query()->findOrFail($admission);

        $student = $action->execute((int) $model->id, $collegeId, $request->validated());
        $enrollment = $student->enrollments->first();

        $message = 'Student '.$student->student_number.' created from admission '.$model->admission_number.'.';
        if ($enrollment) {
            $message .= ' Enrollment '.$enrollment->enrollment_number.'.';
        }

        return redirect()->route('students.show', $student)->with('success', $message);
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
     *
     * Streamed INLINE (not as an attachment): the response is used as the `src`
     * of an <img> on the profile and the ID card, and browsers refuse to render
     * a response that carries `Content-Disposition: attachment`. Document
     * downloads keep the attachment behaviour — this is the only read path that
     * must render in place.
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

        return $files->inline($path);
    }

    /**
     * Edit the student's own profile fields.
     *
     * Enrollments are NOT edited here — they own their own module and policy —
     * so the form shows the current enrollment and links to it, while the
     * "Documents" section links to the student's document register (the count
     * is read tenant-scoped through the Student relation).
     */
    public function edit(Request $request, string $student): View
    {
        $model = $this->findScoped($student);
        $this->authorize('update', $model);

        return view('students.edit', array_merge([
            'student' => $model,
            'documentsCount' => $model->documents()->count(),
        ], $this->profileFormOptions($request)));
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

    /**
     * Option lists for the profile form's optional first-enrollment block.
     *
     * Every list is tenant-scoped by CollegeScope, and the section list carries
     * the academic year/program it belongs to so the view can narrow it (with
     * the server re-validating the combination in StudentService).
     *
     * An unauthorized user gets empty lists: the block is not rendered, and the
     * Form Request removes the fields anyway.
     *
     * @return array<string, \Illuminate\Support\Collection<int, mixed>>
     */
    /**
     * Compatible Student Create fields snapshotted from Admission → Application
     * → Applicant. Parent/guardian columns do not exist on the applicant, so
     * they are left blank for the operator to complete on the same form.
     *
     * @return array<string, mixed>
     */
    private function defaultsFromAdmission(Admission $admission): array
    {
        $applicant = $admission->applicant;
        $application = $admission->application;

        return [
            'first_name' => $applicant?->first_name,
            'middle_name' => $applicant?->middle_name,
            'last_name' => $applicant?->last_name,
            'email' => $applicant?->email,
            'phone' => $applicant?->phone,
            'alternate_phone' => $applicant?->alternate_phone,
            'gender' => $applicant?->gender,
            'date_of_birth' => $applicant?->date_of_birth?->format('Y-m-d'),
            'address_line_1' => $applicant?->address,
            'status' => 'active',
            'admission_date' => $admission->admission_date?->format('Y-m-d'),
            'academic_year_id' => $admission->academic_year_id ?? $application?->academic_year_id,
            'program_id' => $admission->program_id ?? $application?->program_id,
            'enrollment_date' => $admission->admission_date?->format('Y-m-d'),
            'remarks' => $admission->remarks,
        ];
    }

    private function profileFormOptions(Request $request): array
    {
        if (! $request->user()?->can('create', StudentEnrollment::class)) {
            return ['academicYears' => collect(), 'programs' => collect(), 'sections' => collect()];
        }

        return [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()
                ->with(['academicYear:id,name', 'program:id,name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
        ];
    }
}
