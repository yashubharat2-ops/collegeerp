<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\ExamResult;
use App\Models\Marksheet;
use App\Models\Program;
use App\Models\Section;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\ResultQueryService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marksheets — printable published examination results (Examinations Phase 4A).
 *
 * Read-only by design: this controller displays PUBLISHED calculated result
 * data as an academic marksheet and never creates marks or results. The data
 * chain it reads is
 *
 *   Examination → ExamSchedule → ExamMark → (calculation) → ExamResult
 *
 * and the read path is shared with the Results module through
 * ResultQueryService, always with unpublished rows excluded — there is no
 * second result query or calculation system here.
 *
 * Tenant isolation: ExamResult carries CollegeScope, so every query resolves
 * inside the active college only; cross-college ids 404 via findOrFail.
 */
class MarksheetController extends Controller
{
    public function __construct(private readonly ResultQueryService $query)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Marksheet::class);

        // Marksheets are published-only: unlike the administrative Results
        // list, there is no unpublished visibility, whatever the request says.
        $filters = $this->query->currentFilters($request);

        return view('marksheets.index', array_merge($this->filterOptions(), [
            'results' => $this->query->paginate($request, false),
            'filters' => $filters,
            'resultStatuses' => ExamResult::RESULT_STATUSES,
        ]));
    }

    /**
     * CSV of the filtered marksheet list, or of an authorized selection of it.
     *
     * Destination of the listing's bulk "Export selected" action. The "records"
     * behind a marksheet are PUBLISHED ExamResult rows, so:
     *
     *  - the ids were re-queried inside the active college and every row passed
     *    the marksheet policy's published-only rule in the bulk action handler;
     *  - this endpoint re-applies the published-only rule unconditionally
     *    (`baseQuery(false)`), so an unpublished id sent by hand still cannot
     *    reach the CSV;
     *  - the same ResultQueryService filters as the screen are applied, and the
     *    ids are shape-checked (ListSelection) and re-resolved inside the tenant.
     *
     * The CSV carries the marksheet summary line only (marks, percentage, grade,
     * result and publication date). Subject-level detail stays in the printed
     * marksheet view — and no Aadhaar / government id or file path is exported
     * anywhere. Read-only: nothing is created, published or edited here.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', Marksheet::class);

        $query = $this->query
            ->applyFilters($this->query->baseQuery(false), $request, false)
            ->with(['examination', 'studentEnrollment.student', 'studentEnrollment.program', 'studentEnrollment.section', 'gradeScale'])
            ->reorder('exam_results.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('exam_results.id', $ids);
        }

        $audit->record('marksheets.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('marksheets-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Enrollment number', 'Student number', 'Student', 'Examination', 'Program', 'Section',
                'Total obtained', 'Total max', 'Percentage', 'Grade', 'Result status', 'Published at',
            ])
            ->map(function (ExamResult $result): array {
                $enrollment = $result->studentEnrollment;

                return [
                    $enrollment?->enrollment_number,
                    $enrollment?->student?->student_number,
                    $enrollment?->student?->fullName(),
                    $result->examination?->name,
                    $enrollment?->program?->name,
                    $enrollment?->section?->name,
                    $result->total_obtained_marks,
                    $result->total_max_marks,
                    $result->percentage,
                    $result->overall_grade,
                    $result->result_status,
                    $result->published_at?->format('Y-m-d'),
                ];
            })
            ->streamFromQuery($query);
    }

    public function show(string $result, TenantContext $tenant): View
    {
        $model = ExamResult::query()->findOrFail($result);

        $this->authorize('view', Marksheet::fromResult($model));

        $model->load([
            'examination',
            'academicYear',
            'academicTerm',
            'gradeScale',
            'studentEnrollment.student',
            'studentEnrollment.program',
            'studentEnrollment.section',
            'items.examSchedule.subject',
            'items.subject',
            'calculatedBy',
            'publishedBy',
        ]);

        return view('marksheets.show', [
            'result' => $model,
            'college' => $tenant->college(),
        ]);
    }

    private function filterOptions(): array
    {
        return [
            'examinations' => Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }
}
