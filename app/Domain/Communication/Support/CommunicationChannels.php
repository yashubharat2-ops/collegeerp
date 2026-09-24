<?php

namespace App\Domain\Communication\Support;

/**
 * Delivery channels of Communication Management Phase 2 (templates + logs).
 *
 * Exactly two channels exist: SMS and e-mail. A channel is only a
 * classification of a template / log row — Phase 2 connects to NO external
 * SMS or e-mail provider.
 */
final class CommunicationChannels
{
    public const SMS = 'sms';

    public const EMAIL = 'email';

    /** @var array<string, string> */
    public const LABELS = [
        self::SMS => 'SMS',
        self::EMAIL => 'Email',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(mixed $channel): bool
    {
        return is_string($channel) && array_key_exists($channel, self::LABELS);
    }

    public static function label(?string $channel): string
    {
        return self::LABELS[$channel] ?? ucfirst((string) $channel);
    }

    /** Only the e-mail channel carries a subject line. */
    public static function supportsSubject(?string $channel): bool
    {
        return $channel === self::EMAIL;
    }
}
