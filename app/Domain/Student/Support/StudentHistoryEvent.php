<?php

namespace App\Domain\Student\Support;

use Carbon\CarbonInterface;

/**
 * One entry in a student's consolidated lifecycle history.
 *
 * History is DERIVED, not duplicated: events are assembled on the fly from the
 * records that already exist (admission application, admission, student row,
 * enrollments, academic records, promotions, transfers, documents) plus the
 * append-only audit log. There is deliberately no "student_history" table —
 * duplicating every module's rows into one table would create a second source
 * of truth that can drift out of sync with the modules it summarises.
 *
 * Ordering is deterministic: every event carries a stable sort key built from
 * its timestamp, a fixed category rank (so same-timestamp events always appear
 * in the same relative order) and the originating record's id.
 */
final class StudentHistoryEvent
{
    /**
     * Fixed category ranks. These only decide the relative order of events that
     * share the exact same timestamp, so the timeline stays stable between
     * requests instead of depending on query order.
     */
    private const CATEGORY_RANKS = [
        'admission' => 10,
        'student' => 20,
        'enrollment' => 30,
        'academic' => 40,
        'promotion' => 50,
        'transfer' => 60,
        'document' => 70,
        'audit' => 80,
    ];

    public function __construct(
        public readonly CarbonInterface $occurredAt,
        public readonly string $category,
        public readonly string $label,
        public readonly string $description,
        public readonly ?string $reference = null,
        public readonly int $referenceId = 0,
        public readonly string $source = 'record',
    ) {}

    public function categoryRank(): int
    {
        return self::CATEGORY_RANKS[$this->category] ?? 90;
    }

    /**
     * Stable, total sort key: timestamp, then category rank, then record id.
     */
    public function sortKey(): string
    {
        return sprintf('%011d|%02d|%011d', $this->occurredAt->getTimestamp(), $this->categoryRank(), $this->referenceId);
    }

    public function occurredOn(): string
    {
        return $this->occurredAt->format('d M Y');
    }

    public function occurredAtTime(): string
    {
        return $this->occurredAt->format('d M Y H:i');
    }
}
