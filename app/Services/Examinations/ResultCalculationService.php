<?php

namespace App\Services\Examinations;

use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamResultItem;
use App\Models\ExamSchedule;
use App\Models\Examination;
use App\Models\GradeScale;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\Support\ResultCalculationReport;
use App\Services\Examinations\Support\SubjectOutcome;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ResultCalculationService — the Examinations Phase 3 calculation engine.
 *
 * Flow (strictly one direction, ExamMark is never written to):
 *
 *   Examination → ExamSchedules → ExamMarks → Grade/Pass-Fail rules
 *              → ExamResult + ExamResultItems → (publishing is a separate step)
 *
 * Guarantees:
 *   - Transaction safe: one transaction per run, all-or-nothing.
 *   - Re-runnable: the same (college, examination, enrollment) row is updated
 *     in place; recalculation refreshes items instead of duplicating them.
 *   - Never creates duplicate results (application-level updateOrCreate guard
 *     plus the partial unique index on SQLite/Postgres).
 *   - Never duplicates marks: results are a snapshot of ExamMark, not a copy
 *     of the marks store.
 *
 * Tenant safety: every lookup goes through CollegeScope under the ACTIVE tenant
 * context; examination_id / student_enrollment_id / grade_scale_id are validated
 * against that context before anything is written.
 */
class ResultCalculationService
{
    public function __construct(
        private readonly ExamEligibilityService $eligibility,
        private readonly ResultRuleService $rules,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Calculate (or recalculate) results for one examination.
     *
     * @param  array{program_id?: int|null, section_id?: int|null, student_enrollment_id?: int|null}  $scope
     *
     * @throws ValidationException when the examination, grade scale or scope is
     *                             not valid for the active college.
     */
    public function calculate(
        Examination $examination,
        ?GradeScale $gradeScale,
        array $scope,
        User $actor,
        bool $recalculation = false,
    ): ResultCalculationReport {
        $college = app(TenantContext::class)->require();

        // --- Context validation (never trust browser-supplied ids) ----------
        abort_unless((int) $examination->college_id === (int) $college->getKey(), 403);

        if ($gradeScale !== null) {
            abort_unless((int) $gradeScale->college_id === (int) $college->getKey(), 403);

            if (! $gradeScale->isActive()) {
                throw ValidationException::withMessages([
                    'grade_scale_id' => 'The selected grade scale is not active.',
                ]);
            }

            if (! $this->rules->gradeScaleIsUsable($gradeScale)) {
                throw ValidationException::withMessages([
                    'grade_scale_id' => 'The selected grade scale has an invalid configuration. Fix its grade bands before calculating results.',
                ]);
            }
        }

        // --- Required exam schedules ---------------------------------------
        $schedules = $examination->schedules()
            ->with('subject')
            ->when(! empty($scope['program_id']), fn ($q) => $q->where('program_id', (int) $scope['program_id']))
            ->when(! empty($scope['section_id']), fn ($q) => $q->where('section_id', (int) $scope['section_id']))
            ->where('status', '!=', ExamSchedule::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'examination_id' => 'The selected examination has no exam schedules available for result calculation.',
            ]);
        }

        // --- Eligible enrollments ------------------------------------------
        $enrollments = $this->resolveEnrollments($schedules, $scope);

        if ($enrollments->isEmpty()) {
            throw ValidationException::withMessages([
                'examination_id' => 'No eligible student enrollments were found for the selected scope.',
            ]);
        }

        $scheduleIds = $schedules->pluck('id')->all();
        $enrollmentIds = $enrollments->pluck('id')->all();

        // One tenant-scoped fetch for every captured mark we need. ExamMark is
        // the SOURCE OF TRUTH here — nothing is written back to it.
        $marks = ExamMark::query()
            ->whereIn('exam_schedule_id', $scheduleIds)
            ->whereIn('student_enrollment_id', $enrollmentIds)
            ->get()
            ->keyBy(fn (ExamMark $mark): string => $mark->exam_schedule_id.':'.$mark->student_enrollment_id);

        $report = new ResultCalculationReport($examination, $gradeScale, $recalculation);

        DB::transaction(function () use ($enrollments, $schedules, $marks, $gradeScale, $examination, $actor, $college, $report): void {
            foreach ($enrollments as $enrollment) {
                $report->record(
                    $this->calculateForEnrollment($examination, $enrollment, $schedules, $marks, $gradeScale, $actor, $college->getKey())
                );
            }
        });

        // One traceable audit entry per run instead of one row per student.
        $this->audit->record($report->action(), $examination, [], $report->toArray());

        return $report;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    public function recalculate(
        Examination $examination,
        ?GradeScale $gradeScale,
        array $scope,
        User $actor,
    ): ResultCalculationReport {
        return $this->calculate($examination, $gradeScale, $scope, $actor, recalculation: true);
    }

    /**
     * Build (or refresh) the ExamResult snapshot for one enrollment.
     *
     * @param  Collection<int, ExamSchedule>  $schedules
     * @param  Collection<string, ExamMark>  $marks
     */
    private function calculateForEnrollment(
        Examination $examination,
        StudentEnrollment $enrollment,
        Collection $schedules,
        Collection $marks,
        ?GradeScale $gradeScale,
        User $actor,
        int $collegeId,
    ): ExamResult {
        $outcomes = [];

        foreach ($schedules as $schedule) {
            $outcomes[] = $this->rules->subjectOutcome(
                $schedule,
                $marks->get($schedule->getKey().':'.$enrollment->getKey()),
                $gradeScale,
            );
        }

        $invalid = collect($outcomes)->contains(fn (SubjectOutcome $o): bool => ! $o->valid);
        $resultStatus = $this->rules->overallStatus(array_map(fn (SubjectOutcome $o): string => $o->status, $outcomes));
        $percentage = $this->rules->percentage($outcomes, $totalMax, $totalObtained);

        $calculationStatus = match (true) {
            $invalid => ExamResult::CALCULATION_FAILED,
            collect($outcomes)->contains(fn (SubjectOutcome $o): bool => $o->status === ExamResult::RESULT_INCOMPLETE) => ExamResult::CALCULATION_INCOMPLETE,
            default => ExamResult::CALCULATION_CALCULATED,
        };

        $overallGrade = $calculationStatus === ExamResult::CALCULATION_CALCULATED
            ? $this->rules->resolveGrade($gradeScale, $percentage, $resultStatus)?->grade
            : null;

        $now = now();

        // Re-runnable: one row per (college, examination, enrollment), updated
        // in place. The partial unique index backs this on SQLite/Postgres;
        // MySQL/MariaDB relies on this guard alone.
        $result = ExamResult::query()->firstOrNew([
            'college_id' => $collegeId,
            'examination_id' => $examination->getKey(),
            'student_enrollment_id' => $enrollment->getKey(),
        ]);

        $isNew = ! $result->exists;

        $result->fill([
            'academic_year_id' => $enrollment->academic_year_id,
            'academic_term_id' => $examination->academic_term_id,
            'grade_scale_id' => $gradeScale?->getKey(),
            'total_max_marks' => round($totalMax ?? 0.0, 2),
            'total_obtained_marks' => $percentage === null ? null : round($totalObtained ?? 0.0, 2),
            'percentage' => $percentage,
            'overall_grade' => $overallGrade,
            'result_status' => $resultStatus,
            'calculation_status' => $calculationStatus,
            'calculated_at' => $now,
            'calculated_by' => $actor->getKey(),
            'created_by' => $isNew ? $actor->getKey() : $result->created_by,
            'updated_by' => $actor->getKey(),
        ]);

        try {
            $result->save();
        } catch (QueryException) {
            // Racing insert against the partial unique index: read again.
            $result = ExamResult::query()
                ->where('college_id', $collegeId)
                ->where('examination_id', $examination->getKey())
                ->where('student_enrollment_id', $enrollment->getKey())
                ->firstOrFail();

            $result->fill([
                'academic_year_id' => $enrollment->academic_year_id,
                'academic_term_id' => $examination->academic_term_id,
                'grade_scale_id' => $gradeScale?->getKey(),
                'total_max_marks' => round($totalMax ?? 0.0, 2),
                'total_obtained_marks' => $percentage === null ? null : round($totalObtained ?? 0.0, 2),
                'percentage' => $percentage,
                'overall_grade' => $overallGrade,
                'result_status' => $resultStatus,
                'calculation_status' => $calculationStatus,
                'calculated_at' => $now,
                'calculated_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();
        }

        $this->syncItems($result, $outcomes, $collegeId, $actor);

        return $result->refresh();
    }

    /**
     * Refresh the per-subject snapshot rows for a result.
     *
     * Rows are matched on (exam_result_id, exam_schedule_id) so recalculation
     * updates the existing rows instead of duplicating them; rows for schedules
     * that dropped out of scope are removed.
     *
     * @param  list<SubjectOutcome>  $outcomes
     */
    private function syncItems(ExamResult $result, array $outcomes, int $collegeId, User $actor): void
    {
        $kept = [];

        foreach ($outcomes as $outcome) {
            $item = ExamResultItem::query()->firstOrNew([
                'exam_result_id' => $result->getKey(),
                'exam_schedule_id' => $outcome->examScheduleId,
            ]);

            $item->fill([
                'college_id' => $collegeId,
                'subject_id' => $outcome->subjectId,
                'max_marks' => $outcome->maxMarks,
                'passing_marks' => $outcome->passingMarks,
                'obtained_marks' => $outcome->obtainedMarks,
                'grade' => $outcome->grade,
                'status' => $outcome->status,
                'remarks' => $outcome->remarks,
            ]);

            try {
                $item->save();
            } catch (QueryException) {
                $item = ExamResultItem::query()
                    ->where('exam_result_id', $result->getKey())
                    ->where('exam_schedule_id', $outcome->examScheduleId)
                    ->firstOrFail();

                $item->fill([
                    'subject_id' => $outcome->subjectId,
                    'max_marks' => $outcome->maxMarks,
                    'passing_marks' => $outcome->passingMarks,
                    'obtained_marks' => $outcome->obtainedMarks,
                    'grade' => $outcome->grade,
                    'status' => $outcome->status,
                    'remarks' => $outcome->remarks,
                ])->save();
            }

            $kept[] = $item->getKey();
        }

        $result->allItems()->whereNotIn('id', $kept)->delete();
    }

    /**
     * Resolve the enrollments in scope, reusing the single Phase 2 eligibility
     * source of truth (year / section / program + Academics subject enrollment).
     *
     * @param  Collection<int, ExamSchedule>  $schedules
     * @param  array<string, mixed>  $scope
     * @return Collection<int, StudentEnrollment>
     */
    private function resolveEnrollments(Collection $schedules, array $scope): Collection
    {
        $ids = [];

        foreach ($schedules as $schedule) {
            foreach ($this->eligibility->eligibleEnrollments($schedule)->pluck('id')->all() as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if (! empty($scope['student_enrollment_id'])) {
            $requested = (int) $scope['student_enrollment_id'];

            if (! isset($ids[$requested])) {
                throw ValidationException::withMessages([
                    'student_enrollment_id' => 'The selected student enrollment is not eligible for this examination.',
                ]);
            }

            $ids = [$requested => $requested];
        }

        return StudentEnrollment::query()
            ->whereIn('id', array_values($ids))
            ->with(['student', 'program', 'section', 'academicYear'])
            // Deterministic processing order.
            ->orderBy('id')
            ->get();
    }
}
