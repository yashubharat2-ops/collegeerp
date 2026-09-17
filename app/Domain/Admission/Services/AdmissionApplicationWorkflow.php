<?php

namespace App\Domain\Admission\Services;

/**
 * Configurable status workflow for admission applications.
 *
 * Keeps rules extensible rather than hard-coded to one institution.
 * Transitions are defined as map from current status to allowed next statuses.
 * Adding 'admitted' as terminal state derived from approved/selected.
 *
 * Usage:
 *  AdmissionApplicationWorkflow::canTransition('draft','submitted') => true
 *  AdmissionApplicationWorkflow::allowedFrom('draft') => [...]
 */
final class AdmissionApplicationWorkflow
{
    /**
     * Define allowed transitions.
     * Keys are source statuses, values are list of target statuses.
     * Self-transition (no change) is always allowed to permit editing without status change.
     */
    private const TRANSITIONS = [
        // A draft may be sent directly to review by institutions that do not
        // use a separate submission step. Submitted applications can also be
        // returned to draft for correction before review.
        'draft' => ['submitted', 'under_review'],
        'submitted' => ['under_review', 'draft'],
        'under_review' => ['approved'],
        'approved' => ['admitted'],
        'rejected' => ['draft'],
        'cancelled' => ['draft'],
        'admitted' => ['admitted', 'cancelled'],
    ];

    public const STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'cancelled', 'admitted'];

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function allStatuses(): array
    {
        return self::STATUSES;
    }
}
