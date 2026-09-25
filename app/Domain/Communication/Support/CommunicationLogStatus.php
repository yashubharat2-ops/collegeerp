<?php

namespace App\Domain\Communication\Support;

/**
 * Lifecycle of a communication log row (Communication Management, Phase 2).
 *
 *   queued → sent → delivered
 *   queued → failed,  sent → failed
 *
 * A delivered or failed log is terminal: the record is immutable afterwards.
 * No transition talks to an external gateway — the status is only recorded.
 */
final class CommunicationLogStatus
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    /** @var array<int, string> */
    public const ALL = [self::QUEUED, self::SENT, self::DELIVERED, self::FAILED];

    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        self::QUEUED => [self::SENT, self::DELIVERED, self::FAILED],
        self::SENT => [self::DELIVERED, self::FAILED],
        self::DELIVERED => [],
        self::FAILED => [],
    ];

    public static function isValid(mixed $status): bool
    {
        return is_string($status) && in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function label(?string $status): string
    {
        return ucfirst((string) $status);
    }
}
