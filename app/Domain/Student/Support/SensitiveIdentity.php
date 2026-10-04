<?php

namespace App\Domain\Student\Support;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * At-rest protection for the identity numbers the Student profile stores.
 *
 * Two rules are enforced here, once, so no controller, view or export has to
 * remember them:
 *
 * 1. A sensitive identity value is NEVER persisted in clear text. It is stored
 *    as a Laravel `Crypt` payload (AES-256 with the application key), which is
 *    why the columns are `text`: the ciphertext is far longer than the number.
 * 2. A sensitive identity value is NEVER rendered in full. Only the tail
 *    (default: last four characters) may be shown, prefixed with the same
 *    number of mask characters, so a masked display can never accidentally
 *    reveal a short value either.
 *
 * Decryption intentionally degrades to `null` instead of throwing: a value that
 * cannot be decrypted (a rotated APP_KEY, or a row written by a tool other than
 * this application) must never crash a read path — the profile simply shows
 * "not recorded" rather than leaking or 500-ing.
 */
final class SensitiveIdentity
{
    /** Characters kept visible by maskTail(). */
    public const VISIBLE_TAIL = 4;

    public static function encrypt(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            // Never store an encryption of "" — an empty identity is a NULL
            // column, so "not recorded" is unambiguous.
            return null;
        }

        return Crypt::encryptString($value);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mask everything but the tail: "ABCDEF1234" → "XXXXXX1234".
     *
     * The visible part is never longer than the mask, so a value shorter than
     * the visible window is fully masked instead of being exposed in full.
     */
    public static function maskTail(?string $value, int $visible = self::VISIBLE_TAIL): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $length = strlen($value);

        if ($length <= $visible) {
            return str_repeat('X', $length);
        }

        return str_repeat('X', $length - $visible).substr($value, -$visible);
    }
}
