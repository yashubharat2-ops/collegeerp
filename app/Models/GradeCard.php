<?php

namespace App\Models;

/**
 * GradeCard — a derived, read-only examination document (Examinations Phase 4).
 *
 * This is deliberately NOT an Eloquent model: there is no `grade_cards` table,
 * no grade-card rows are ever created, and no grades, grade points or credits
 * are stored here. A grade card is a printable projection of one
 * already-published ExamResult (plus its ExamResultItems), which remain the
 * single source of truth for all result data. Subject credits come from the
 * existing Subject master and grade points from the result's own GradeScale —
 * both are plain lookups of stored values, never new calculations (no
 * SGPA/CGPA, no credit weighting is performed anywhere here).
 *
 * The wrapper exists so the read-only document has its own authorization
 * boundary: GradeCardPolicy is registered against this class, keeping the
 * `grade_cards.view` permission and the published-only rule separate from the
 * administrative Results policy and from the sibling Marksheets policy.
 */
final class GradeCard
{
    public function __construct(public readonly ExamResult $result)
    {
    }

    public static function fromResult(ExamResult $result): self
    {
        return new self($result);
    }

    /**
     * The underlying published result id, so views and routes keep working
     * with the familiar `{result}` parameter.
     */
    public function getKey(): mixed
    {
        return $this->result->getKey();
    }

    public function getRouteKey(): mixed
    {
        return $this->result->getRouteKey();
    }
}
