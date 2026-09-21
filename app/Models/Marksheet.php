<?php

namespace App\Models;

/**
 * Marksheet — a derived, read-only examination document (Examinations Phase 4A).
 *
 * This is deliberately NOT an Eloquent model: there is no `marksheets` table,
 * no marksheet rows are ever created, and no marks, grades or totals are stored
 * here. A marksheet is a printable projection of one already-published
 * ExamResult (plus its ExamResultItems), which remain the single source of
 * truth for all result data.
 *
 * The wrapper exists so the read-only document has its own authorization
 * boundary: MarksheetPolicy is registered against this class, keeping the
 * `marksheets.view` permission and the published-only rule separate from the
 * administrative Results policy (which additionally governs unpublished rows,
 * calculation and publishing).
 */
final class Marksheet
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
