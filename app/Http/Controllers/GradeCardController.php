<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\ExamResult;
use App\Models\GradeCard;
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
 * Grade Cards — printable published examination results with grades, grade
 * points and credits (Examinations Phase 4).
 *
 * Read-only by design: this controller displays PUBLISHED calculated result
 * data as an academic grade card and never creates marks, results or grades.
 * The read path is shared with the Results module through ResultQueryService,
 * always with unpublished rows excluded — there is no second result query or
 * calculation system here.
 *
 * Grade points are plain lookups of the result's own stored GradeScale rows
 * and credits come from the existing Subject master; no SGPA/CGPA, no credit
 * weighting and no new grade arithmetic is performed anywhere.
 *
 * Tenant isolation: ExamResult carries CollegeScope, so every query resolves
 * inside the active college only; cross-college ids 404 via findOrFail.
 */
class GradeCardController extends Controller
{
    public function __construct(private readonly ResultQueryService $query)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', GradeCard::class);

        return view('grade_cards.index', array_merge($this->filterOptions(), [
            'results' => $this->query->paginate($request, false),
            'filters' => $this->query->currentFilters($request),
            'resultStatuses' => ExamResult::RESULT_STATUSES,
        ]));
    }

    /**
     * CSV of the filtered grade-card list, or of an authorized selection of it.
     *
     * Same contract as the marksheet export: a grade card exists only for a
     * PUBLISHED result, so the published-only rule is applied here
     * unconditionally, the ids were already re-queried inside the active college
     * and authorized per record by the bulk action handler, and the screen's
     * filters are re-applied through the shared ResultQueryService.
     *
     * The CSV is the grade-card summary line (grade, grade scale, result,
     * publication date); per-subject grade points and credits stay in the
     * printed grade card. No identity number and no file path is exported.
     * Read-only: nothing is written by this endpoint.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', GradeCard::class);

        $query = $this->query
            ->applyFilters($this->query->baseQuery(false), $request, false)
            ->with(['examination', 'studentEnrollment.student', 'studentEnrollment.program', 'studentEnrollment.section', 'gradeScale'])
            ->reorder('exam_results.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('exam_results.id', $ids);
        }

        $audit->record('grade_cards.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('grade-cards-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Enrollment number', 'Student number', 'Student', 'Examination', 'Program', 'Section',
                'Grade scale', 'Grade', 'Total obtained', 'Total max', 'Percentage', 'Result status', 'Published at',
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
                    $result->gradeScale?->name,
                    $result->overall_grade,
                    $result->total_obtained_marks,
                    $result->total_max_marks,
                    $result->percentage,
                    $result->result_status,
                    $result->published_at?->format('Y-m-d'),
                ];
            })
            ->streamFromQuery($query);
    }

    public function show(string $result, TenantContext $tenant): View
    {
        $model = ExamResult::query()->findOrFail($result);

        $this->authorize('view', GradeCard::fromResult($model));

        $model->load([
            'examination',
            'academicYear',
            'academicTerm',
            'gradeScale.items',
            'studentEnrollment.student',
            'studentEnrollment.program',
            'studentEnrollment.section',
            'items.examSchedule.subject',
            'items.subject',
            'calculatedBy',
            'publishedBy',
        ]);

        return view('grade_cards.show', [
            'result' => $model,
            'college' => $tenant->college(),
            'gradePoints' => $this->gradePointMap($model),
        ]);
    }

    /**
     * Stored grade letter → stored grade point, from the result's own grade
     * scale. A lookup of configured data, not a calculation.
     *
     * @return array<string, string|null>
     */
    private function gradePointMap(ExamResult $result): array
    {
        $map = [];

        foreach ($result->gradeScale?->items ?? [] as $band) {
            $map[$band->grade] = $band->grade_point !== null ? (string) $band->grade_point : null;
        }

        return $map;
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
