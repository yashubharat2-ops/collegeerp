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
use App\Services\Examinations\ResultCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
    public function __construct(private readonly ResultCalculationService $calculator)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewCalculation', ExamResult::class);

        $selectedExamination = $request->filled('examination_id')
            ? Examination::query()->find((int) $request->input('examination_id'))
            : null;

        return view('result_calculation.index', array_merge($this->filterOptions(), [
            'examination' => $selectedExamination,
            'summary' => $selectedExamination ? $this->summarise($selectedExamination) : null,
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
