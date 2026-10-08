<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResultCalculation\CalculateResultRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ExamResult;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\GradeScale;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\ResultCalculationService;
use App\Services\Examinations\ResultQueryService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Result Calculation (Examinations Phase 3).
 *
 * Drives the calculation engine only — it never publishes anything and never
 * writes marks. The flow it exposes mirrors the domain flow:
 *
 *   Exam → Exam Schedules → Exam Marks → Grade / Pass-Fail rules
 *       → Calculated Result → (publication is a separate step)
 *
 * Calculation is transaction-safe and re-runnable: running it again refreshes
 * the existing snapshots instead of duplicating results.
 *
 * Tenant isolation: every dropdown and every referenced id is resolved through
 * CollegeScope under the active college.
 */
class ResultCalculationController extends Controller
{
    public function __construct(
        private readonly ResultCalculationService $calculator,
        private readonly ResultQueryService $query,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewCalculation', ExamResult::class);

        $selectedExamination = $request->filled('examination_id')
            ? Examination::query()->find((int) $request->input('examination_id'))
            : null;

        // The calculation worklist lists RESULTS, so it is only rendered for a
        // user who may read results at all (`results.view`): the
        // `result_calculation.*` permissions grant the engine, never per-student
        // result data. Unpublished rows follow the Results rule exactly —
        // visible only with `results.view_unpublished` — and the model carries
        // CollegeScope, so the listing and its export are tenant-scoped.
        $canViewResults = $request->user()?->can('viewAny', ExamResult::class) ?? false;
        $canViewUnpublished = $request->user()?->can('viewUnpublished', ExamResult::class) ?? false;

        return view('result_calculation.index', array_merge($this->filterOptions(), [
            'examination' => $selectedExamination,
            'summary' => $selectedExamination ? $this->summarise($selectedExamination) : null,
            'canViewResults' => $canViewResults,
            'results' => $canViewResults
                ? $this->scopedResults($request, $canViewUnpublished)
                    ->orderByDesc('exam_results.id')
                    ->paginate(15)
                    ->withQueryString()
                : null,
            'canCalculate' => $request->user()?->can('calculate', ExamResult::class) ?? false,
            'canRecalculate' => $request->user()?->can('recalculate', ExamResult::class) ?? false,
            'filters' => [
                'examination_id' => $request->input('examination_id'),
                'grade_scale_id' => $request->input('grade_scale_id'),
                'program_id' => $request->input('program_id'),
                'section_id' => $request->input('section_id'),
                'student_enrollment_id' => $request->input('student_enrollment_id'),
            ],
        ]));
    }

    public function calculate(CalculateResultRequest $request): RedirectResponse
    {
        $report = $this->calculator->calculate(
            $request->examination(),
            $this->gradeScale($request),
            $request->scope(),
            $request->user(),
        );

        return redirect()
            ->route('result-calculation.index', ['examination_id' => $report->examination->getKey()])
            ->with('success', "Calculated {$report->processed} result(s).");
    }

    public function recalculate(CalculateResultRequest $request): RedirectResponse
    {
        $report = $this->calculator->recalculate(
            $request->examination(),
            $this->gradeScale($request),
            $request->scope(),
            $request->user(),
        );

        return redirect()
            ->route('result-calculation.index', ['examination_id' => $report->examination->getKey()])
            ->with('success', "Recalculated {$report->processed} result(s).");
    }

    /**
     * The results inside the calculation scope on screen.
     *
     * One definition, two consumers: the worklist table `index()` renders and the
     * bulk "Export selected" endpoint. It reuses ResultQueryService for every
     * filter the Results module already understands (examination, year, term,
     * program, section, search, statuses) and adds the two scopes only this
     * screen offers (grade scale, single enrollment), so a CSV can never contain
     * a result the screen would not show.
     */
    private function scopedResults(Request $request, bool $canViewUnpublished): Builder
    {
        $query = $this->query
            ->applyFilters($this->query->baseQuery($canViewUnpublished), $request, $canViewUnpublished)
            ->with(['examination', 'studentEnrollment.student', 'studentEnrollment.program', 'studentEnrollment.section', 'gradeScale']);

        if ($gradeScaleId = $request->input('grade_scale_id')) {
            $query->where('grade_scale_id', (int) $gradeScaleId);
        }

        if ($studentEnrollmentId = $request->input('student_enrollment_id')) {
            $query->where('student_enrollment_id', (int) $studentEnrollmentId);
        }

        return $query;
    }

    /**
     * CSV of the results in the calculation scope, or of an authorized selection
     * of them.
     *
     * Destination of the worklist's bulk "Export selected" action. The ids were
     * re-queried inside the active college and authorized through the Results
     * policy by the bulk action handler; they are shape-checked (ListSelection)
     * and re-resolved here, and `results.view_unpublished` is resolved from the
     * policy rather than from the request — so the export can never be wider than
     * the worklist on screen.
     *
     * Read-only by construction: nothing recalculates, publishes or edits marks
     * from this endpoint. Calculation and recalculation keep their existing
     * scope-validated POST endpoints.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', ExamResult::class);

        $canViewUnpublished = $request->user()?->can('viewUnpublished', ExamResult::class) ?? false;

        $query = $this->scopedResults($request, $canViewUnpublished)->reorder('exam_results.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('exam_results.id', $ids);
        }

        $audit->record('result_calculation.exported', null, [], [
            'selected_ids' => count($ids),
            'include_unpublished' => $canViewUnpublished,
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('result-calculation-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Enrollment number', 'Student number', 'Student', 'Examination', 'Program', 'Section',
                'Grade scale', 'Total obtained', 'Total max', 'Percentage', 'Grade',
                'Calculation status', 'Result status', 'Publishing status', 'Calculated at', 'Published at',
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
                    $result->total_obtained_marks,
                    $result->total_max_marks,
                    $result->percentage,
                    $result->overall_grade,
                    $result->calculation_status,
                    $result->result_status,
                    $result->publication_status,
                    $result->calculated_at?->format('Y-m-d H:i'),
                    $result->published_at?->format('Y-m-d H:i'),
                ];
            })
            ->streamFromQuery($query);
    }

    private function gradeScale(CalculateResultRequest $request): ?GradeScale
    {
        $id = $request->input('grade_scale_id');

        return $id ? GradeScale::query()->findOrFail((int) $id) : null;
    }

    /**
     * Per-examination progress overview: how many results exist, how far along
     * calculation is, and how many are publishable.
     */
    private function summarise(Examination $examination): array
    {
        $results = ExamResult::query()
            ->where('examination_id', $examination->getKey())
            ->get();

        return [
            'schedules' => ExamSchedule::query()
                ->where('examination_id', $examination->getKey())
                ->where('status', '!=', ExamSchedule::STATUS_CANCELLED)
                ->count(),
            'total' => $results->count(),
            'calculated' => $results->where('calculation_status', ExamResult::CALCULATION_CALCULATED)->count(),
            'incomplete' => $results->where('calculation_status', ExamResult::CALCULATION_INCOMPLETE)->count(),
            'failed' => $results->where('calculation_status', ExamResult::CALCULATION_FAILED)->count(),
            'published' => $results->whereNotNull('published_at')->count(),
            'lastCalculatedAt' => $results->max('calculated_at'),
        ];
    }

    private function filterOptions(): array
    {
        return [
            'examinations' => Examination::query()->orderByDesc('id')->get(['id', 'name', 'code', 'status']),
            'gradeScales' => GradeScale::query()
                ->where('status', GradeScale::STATUS_ACTIVE)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'code']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code']),
            'enrollments' => StudentEnrollment::query()
                ->with('student:id,student_number,first_name,middle_name,last_name')
                ->orderBy('enrollment_number')
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'student_id', 'enrollment_number']),
        ];
    }
}
