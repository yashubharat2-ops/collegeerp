<?php

namespace App\Services\Examinations;

use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSchedule;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Services\Examinations\Support\SubjectOutcome;

/**
 * The reusable Result Rule Engine (Examinations Phase 3).
 *
 * Pure domain rules: no persistence, no HTTP, no tenant lookups. Everything is
 * expressed in terms of the marks that already exist and of whatever grading
 * configuration the active college owns.
 *
 * Deliberately NOT hard-coded here:
 *   - grade letters (A/B/C/D/…)
 *   - percentage boundaries (33/40/50/…)
 *   - divisions, SGPA/CGPA formulas
 *
 * The engine is intentionally shaped so SGPA / CGPA / division logic can be
 * added later as another rule over the same SubjectOutcome objects, without
 * touching the calculation or publishing services.
 *
 * Mark status handling (ExamMark is the source of truth):
 *   draft    → NOT a final mark; the subject stays "incomplete"
 *   absent   → stays absent; obtained marks remain NULL
 *   withheld → stays withheld; obtained marks remain NULL
 *   entered  → a real numeric score; validated against max marks
 */
class ResultRuleService
{
    /**
     * Decide the outcome of one exam schedule for one enrollment.
     *
     * @param  ExamSchedule  $schedule  The scheduled paper (owns max/passing marks).
     * @param  ExamMark|null  $mark  The captured mark row, when one exists.
     * @param  GradeScale|null  $gradeScale  The college's configured scale, if any.
     */
    public function subjectOutcome(ExamSchedule $schedule, ?ExamMark $mark, ?GradeScale $gradeScale = null): SubjectOutcome
    {
        $maxMarks = (float) $schedule->max_marks;
        $passingMarks = (float) $schedule->passing_marks;

        // No mark captured at all → required data is missing.
        if ($mark === null) {
            return SubjectOutcome::make($schedule, null, ExamResult::RESULT_INCOMPLETE, null, null);
        }

        // Draft marks are never final marks.
        if ($mark->status === ExamMark::STATUS_DRAFT) {
            return SubjectOutcome::make($schedule, null, ExamResult::RESULT_INCOMPLETE, null, $mark->remarks);
        }

        // Absent / withheld remain absent / withheld; obtained marks stay NULL.
        if ($mark->status === ExamMark::STATUS_ABSENT) {
            return SubjectOutcome::make($schedule, null, ExamResult::RESULT_ABSENT, null, $mark->remarks);
        }

        if ($mark->status === ExamMark::STATUS_WITHHELD) {
            return SubjectOutcome::make($schedule, null, ExamResult::RESULT_WITHHELD, null, $mark->remarks);
        }

        // Structurally invalid data never becomes a pass (or a fail): the row
        // is flagged invalid so the parent result reports calculation_status
        // = failed instead of guessing a score.
        if ($this->marksAreInvalid($maxMarks, $passingMarks, $mark)) {
            return SubjectOutcome::make(
                $schedule,
                null,
                ExamResult::RESULT_INCOMPLETE,
                null,
                'Captured marks failed validation; the result cannot be determined. Ignoring the stored marks.',
                valid: false,
            );
        }

        $obtained = $mark->obtained_marks === null ? null : (float) $mark->obtained_marks;

        // An "entered" row must carry a score; without one the subject is
        // unresolved rather than a silent zero.
        if ($obtained === null) {
            return SubjectOutcome::make($schedule, null, ExamResult::RESULT_INCOMPLETE, null, $mark->remarks);
        }

        $status = $obtained >= $passingMarks
            ? ExamResult::RESULT_PASS
            : ExamResult::RESULT_FAIL;

        $percentage = $maxMarks > 0 ? ($obtained / $maxMarks) * 100 : 0.0;
        $grade = $this->resolveGrade($gradeScale, $percentage, $status)?->grade;

        return SubjectOutcome::make($schedule, $obtained, $status, $grade, $mark->remarks);
    }

    /**
     * Structural validation of one captured mark row.
     *
     * Rejects negative marks, scores above the paper's maximum and papers whose
     * passing marks exceed their maximum.
     */
    private function marksAreInvalid(float $maxMarks, float $passingMarks, ExamMark $mark): bool
    {
        if ($maxMarks <= 0) {
            return true;
        }

        if ($passingMarks < 0 || $passingMarks > $maxMarks) {
            return true;
        }

        if ($mark->max_marks !== null && (float) $mark->max_marks < 0) {
            return true;
        }

        if ($mark->passing_marks !== null) {
            $markPassing = (float) $mark->passing_marks;

            if ($markPassing < 0 || $markPassing > $maxMarks) {
                return true;
            }
        }

        if ($mark->obtained_marks !== null) {
            $obtained = (float) $mark->obtained_marks;

            if ($obtained < 0 || $obtained > $maxMarks) {
                return true;
            }
        }

        return false;
    }

    /**
     * Overall result status from every required subject outcome.
     *
     * Precedence (deterministic, and never infers PASS from a percentage):
     *
     *   withheld > incomplete > fail > absent > pass
     *
     * So a student is never PASS when a required subject failed, never PASS
     * while required marks are still missing, and an absent / withheld subject
     * is never converted into a passing score.
     *
     * @param  list<string>  $statuses
     */
    public function overallStatus(array $statuses): string
    {
        if ($statuses === []) {
            return ExamResult::RESULT_INCOMPLETE;
        }

        $unique = array_values(array_unique($statuses));

        foreach ([
            ExamResult::RESULT_WITHHELD,
            ExamResult::RESULT_INCOMPLETE,
            ExamResult::RESULT_FAIL,
            ExamResult::RESULT_ABSENT,
        ] as $candidate) {
            if (in_array($candidate, $unique, true)) {
                return $candidate;
            }
        }

        return ExamResult::RESULT_PASS;
    }

    /**
     * Percentage over every required paper.
     *
     * Returns NULL while no numeric score exists at all (all absent /
     * withheld / unresolved), so a percentage is never fabricated.
     *
     * @param  list<SubjectOutcome>  $outcomes
     */
    public function percentage(array $outcomes, ?float &$totalMax = null, ?float &$totalObtained = null): ?float
    {
        $totalMax = 0.0;
        $totalObtained = 0.0;
        $scored = false;
        $unresolved = false;

        foreach ($outcomes as $outcome) {
            // Every required paper contributes its maximum, including absent
            // and withheld ones: the denominator is the whole examination.
            $totalMax += $outcome->maxMarks;

            // A missing, draft or invalid mark leaves the examination
            // unresolved, so no percentage is reported at all — a partial
            // percentage would read like a complete result.
            if (! $outcome->valid || $outcome->status === ExamResult::RESULT_INCOMPLETE) {
                $unresolved = true;

                continue;
            }

            if ($outcome->obtainedMarks !== null && $outcome->countsTowardsScore()) {
                $totalObtained += $outcome->obtainedMarks;
                $scored = true;
            }
        }

        if ($unresolved || ! $scored || $totalMax <= 0) {
            return null;
        }

        return round(($totalObtained / $totalMax) * 100, 3);
    }

    /**
     * Resolve the grade band for a percentage from the college's own scale.
     *
     * Grades are only applied where they are meaningful — a passing result with
     * a computable percentage. Failing, absent, withheld and unresolved results
     * keep a NULL grade instead of wearing a letter derived from partial data.
     */
    public function resolveGrade(?GradeScale $gradeScale, ?float $percentage, string $resultStatus): ?GradeScaleItem
    {
        if (! $gradeScale || $percentage === null) {
            return null;
        }

        if ($resultStatus !== ExamResult::RESULT_PASS) {
            return null;
        }

        return $gradeScale->items()
            ->where('status', GradeScaleItem::STATUS_ACTIVE)
            ->get()
            ->first(fn (GradeScaleItem $item): bool => $item->covers($percentage));
    }

    /**
     * Whether the grading configuration behind a result can be trusted.
     *
     * A result without a grade scale is valid (pass/fail is derived from each
     * paper's own passing marks). When a scale IS attached it must be active,
     * structurally sound, and able to grade the achieved percentage — otherwise
     * publishing is blocked as "invalid grade configuration".
     */
    public function hasUsableGradeConfiguration(ExamResult $result): bool
    {
        $scale = $result->gradeScale;

        if ($scale === null) {
            return true;
        }

        if (! $this->gradeScaleIsUsable($scale)) {
            return false;
        }

        if ($result->result_status !== ExamResult::RESULT_PASS || $result->percentage === null) {
            return true;
        }

        return $this->resolveGrade($scale, (float) $result->percentage, $result->result_status) !== null;
    }

    /**
     * Whether a grade scale on its own is fit to be used by the engine.
     *
     * A scale must be active and structurally sound (non-empty, no overlapping
     * or inverted bands) before it may take part in a calculation or a
     * publication.
     */
    public function gradeScaleIsUsable(GradeScale $gradeScale): bool
    {
        if (! $gradeScale->isActive()) {
            return false;
        }

        return app(GradeScaleService::class)->configurationIsUsable($gradeScale);
    }

    /**
     * Whether a calculated result may be published at all.
     *
     * Publishing is separate from calculation: a completed calculation never
     * publishes anything on its own.
     */
    public function isPublishableStatus(string $resultStatus, string $calculationStatus): bool
    {
        if ($calculationStatus !== ExamResult::CALCULATION_CALCULATED) {
            return false;
        }

        return $resultStatus !== ExamResult::RESULT_INCOMPLETE
            && in_array($resultStatus, ExamResult::RESULT_STATUSES, true);
    }
}
