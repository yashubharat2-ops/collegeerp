<?php

namespace App\Domain\Communication\Support;

/**
 * PublicationWorkflow — the shared draft / published / archived lifecycle of
 * Notices and Circulars (Communication Management, Phase 1).
 *
 * Status is NEVER mass-assigned from a form: it only moves through the three
 * workflow actions below, each guarded by the module's `*.publish`
 * permission. Keeping the transition table in one place means Notices and
 * Circulars can never drift apart.
 *
 *   publish   : draft | archived  -> published
 *   unpublish : published | archived -> draft
 *   archive   : draft | published -> archived
 */
final class PublicationWorkflow
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_UNPUBLISH = 'unpublish';

    public const ACTION_ARCHIVE = 'archive';

    /**
     * action => [allowed source statuses, resulting status].
     *
     * @var array<string, array{0: array<int, string>, 1: string}>
     */
    public const TRANSITIONS = [
        self::ACTION_PUBLISH => [[self::DRAFT, self::ARCHIVED], self::PUBLISHED],
        self::ACTION_UNPUBLISH => [[self::PUBLISHED, self::ARCHIVED], self::DRAFT],
        self::ACTION_ARCHIVE => [[self::DRAFT, self::PUBLISHED], self::ARCHIVED],
    ];

    /** Derived (read-time) visibility of a record — never stored. */
    public const VISIBILITY_DRAFT = 'draft';

    public const VISIBILITY_ARCHIVED = 'archived';

    public const VISIBILITY_SCHEDULED = 'scheduled';

    public const VISIBILITY_LIVE = 'live';

    public const VISIBILITY_EXPIRED = 'expired';

    public static function canTransition(string $from, string $action): bool
    {
        return isset(self::TRANSITIONS[$action]) && in_array($from, self::TRANSITIONS[$action][0], true);
    }

    public static function target(string $action): string
    {
        return self::TRANSITIONS[$action][1];
    }

    /** Past-tense verb used in messages and audit action names. */
    public static function pastTense(string $action): string
    {
        return match ($action) {
            self::ACTION_PUBLISH => 'published',
            self::ACTION_UNPUBLISH => 'unpublished',
            self::ACTION_ARCHIVE => 'archived',
            default => $action,
        };
    }

    public static function label(string $status): string
    {
        return ucfirst($status);
    }
}
