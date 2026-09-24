<?php

namespace App\Domain\Communication\Support;

/**
 * Delivery / read tracking states (Communication Management, Phase 2).
 *
 * The state of an internal notification is DERIVED from the timestamps
 * already on the record (sent_at / delivered_at / read_at) — it is never
 * stored a second time and no notification row is duplicated.
 */
final class DeliveryStates
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    /** @var array<string, string> */
    public const LABELS = [
        self::PENDING => 'Pending',
        self::SENT => 'Sent',
        self::DELIVERED => 'Delivered',
        self::READ => 'Read',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(mixed $state): bool
    {
        return is_string($state) && array_key_exists($state, self::LABELS);
    }

    public static function label(?string $state): string
    {
        return self::LABELS[$state] ?? ucfirst((string) $state);
    }

    public static function resolve(mixed $sentAt, mixed $deliveredAt, mixed $readAt): string
    {
        return match (true) {
            $readAt !== null => self::READ,
            $deliveredAt !== null => self::DELIVERED,
            $sentAt !== null => self::SENT,
            default => self::PENDING,
        };
    }
}
