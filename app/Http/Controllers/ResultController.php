<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ExamResult;
use App\Models\Examination;
use App\Models\Program;
use App\Models\Section;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\ResultQueryService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Results — the administrative result viewing layer (Examinations Phase 3).
 *
 * Read-only by design: this controller displays CALCULATED result data and
 * never creates marks or results. The data chain it reads is
 *
 *   Examination → ExamSchedule → ExamMark → (calculation) → ExamResult
 *
 * Unpublished results are only listed / shown for users who hold the
 * dedicated `results.view_unpublished` permission — an unpublished result is
 * never presented as a published one.
 *
 * Tenant isolation: ExamResult carries CollegeScope, so every query resolves
 * inside the active college only.
 */
class ResultController extends Controller
{
    public function __construct(private readonly ResultQueryService $query)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExamResult::class);

        $canViewUnpublished = $request->user()?->can('viewUnpublished', ExamResult::class) ?? false;

        return view('results.index', array_merge($this->filterOptions(), [
            'results' => $this->query->paginate($request, $canViewUnpublished),
            'filters' => $this->query->currentFilters($request),
            'canViewUnpublished' => $canViewUnpublished,
            'resultStatuses' => ExamResult::RESULT_STATUSES,
            'calculationStatuses' => ExamResult::CALCULATION_STATUSES,
            'publicationStatuses' => ExamResult::PUBLICATION_STATUSES,
        ]));
    }

    /**
     * CSV of the filtered results, or of an authorized selection of them.
     *
     * Destination of the listing's bulk "Export selected" action. Three rules the
     * list already obeys are re-applied here, none of them taken from the client:
     *
     *  1. the same ResultQueryService filter pipeline (examination, year, term,
     *     program, section, search, result / calculation / publication status),
     *     so the export can only ever be a sub-set of what the filters select;
     *  2. `results.view_unpublished` is resolved from the POLICY, and without it
     *     the query is pinned to published rows — an unpublished id sent by hand
     *     stays invisible;
     *  3. ids are shape-checked (ListSelection) and re-resolved inside the active
     *     college (ExamResult carries CollegeScope), so a foreign college's result
     *     simply matches nothing.
     *
     * Read-only: results are produced by the calculation engine and this endpoint
     * only streams them.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', ExamResult::class);

        $canViewUnpublished = $request->user()?->can('viewUnpublished', ExamResult::class) ?? false;

        $query = $this->query
            ->applyFilters($this->query->baseQuery($canViewUnpublished), $request, $canViewUnpublished)
            ->with(['examination', 'academicYear', 'academicTerm', 'studentEnrollment.student', 'studentEnrollment.program', 'studentEnrollment.section', 'gradeScale'])
            ->reorder('exam_results.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('exam_results.id', $ids);
        }

        $audit->record('results.exported', null, [], [
            'selected_ids' => count($ids),
            'include_unpublished' => $canViewUnpublished,
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('results-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Enrollment number', 'Student number', 'Student', 'Examination', 'Academic year',
                'Academic term', 'Program', 'Section', 'Total obtained', 'Total max', 'Percentage',
                'Grade', 'Grade scale', 'Result status', 'Calculation status', 'Publishing status', 'Published at',
            ])
            ->map(function (ExamResult $result): array {
                $enrollment = $result->studentEnrollment;

                return [
                    $enrollment?->enrollment_number,
                    $enrollment?->student?->student_number,
                    $enrollment?->student?->fullName(),
                    $result->examination?->name,
                    $result->academicYear?->name,
                    $result->academicTerm?->name,
                    $enrollment?->program?->name,
                    $enrollment?->section?->name,
                    $result->total_obtained_marks,
                    $result->total_max_marks,
                    $result->percentage,
                    $result->overall_grade,
                    $result->gradeScale?->name,
                    $result->result_status,
                    $result->calculation_status,
                    $result->publication_status,
                    $result->published_at?->format('Y-m-d H:i'),
                ];
            })
            ->streamFromQuery($query);
    }

    public function show(string $result): View
    {
        $model = ExamResult::query()->findOrFail($result);

        $this->authorize('view', $model);

        $model->load([
            'examination',
            'academicYear',
            'academicTerm',
            'gradeScale',
            'studentEnrollment.student',
            'studentEnrollment.program',
            'studentEnrollment.section',
            'items.examSchedule.subject',
            'calculatedBy',
            'publishedBy',
        ]);

        return view('results.show', [
            'result' => $model,
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
