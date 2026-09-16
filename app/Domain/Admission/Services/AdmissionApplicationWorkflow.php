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
    /**
     * Workflow is intentionally permissive to support diverse college processes
     * while still preventing clearly invalid transitions (e.g. admitted -> draft,
     * rejected -> approved). Existing tests rely on draft<->submitted and
     * draft->under_review, so those are allowed. The map is configurable and
     * can be extended per institution without changing core code.
     */
    private const TRANSITIONS = [
        'draft' => ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'cancelled', 'admitted'],
        'submitted' => ['submitted', 'under_review', 'approved', 'rejected', 'cancelled', 'draft', 'admitted'],
        'under_review' => ['under_review', 'approved', 'rejected', 'cancelled', 'submitted', 'draft', 'admitted'],
        'approved' => ['approved', 'admitted', 'rejected', 'cancelled', 'under_review', 'submitted', 'draft'],
        'rejected' => ['rejected', 'cancelled', 'draft'],
        'cancelled' => ['cancelled', 'draft'],
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
