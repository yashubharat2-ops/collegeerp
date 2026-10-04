<?php

namespace App\Domain\Student\Support;

/**
 * Aadhaar number handling for the Student profile.
 *
 * The Aadhaar number is a 12-digit national identity number. What this class
 * guarantees:
 *
 * - **Normalisation**: spaces and hyphens a clerk types ("9999 4105 7058") are
 *   stripped once, server-side, so every derived value (ciphertext, last four,
 *   HMAC) is computed from the same canonical digits.
 * - **Structural validation**: exactly 12 digits, never starting with 0 or 1
 *   (UIDAI issues numbers starting 2-9), plus the Verhoeff checksum that Aadhaar
 *   uses as its twelfth digit — the same check UIDAI publishes. A typo is
 *   therefore rejected at the form boundary instead of being persisted.
 * - **Masking**: only the last four digits are ever displayed ("XXXX XXXX
 *   1234"). The full number is never rendered, exported or audited.
 * - **Encryption at rest**: the digits are stored through
 *   {@see SensitiveIdentity} (Crypt / APP_KEY), never in clear text.
 * - **Duplicate detection without decryption**: `hash()` is a deterministic
 *   HMAC of the digits, stored in `students.aadhaar_hash`, so "this Aadhaar is
 *   already recorded for another student in this college" can be answered with
 *   one indexed query. It is an HMAC (keyed with the application key) rather
 *   than a bare SHA-256 so the stored value cannot be brute-forced by
 *   enumerating the 12-digit space from a database dump alone.
 *
 * The duplicate lookup itself is per college by design: the tenant boundary is
 * absolute in this platform, and searching other colleges' rows to detect a
 * cross-college duplicate would be a cross-tenant read.
 */
final class Aadhaar
{
    public const LENGTH = 12;

    /** Aadhaar's first digit is always 2-9. */
    private const FIRST_DIGIT_PATTERN = '/^[2-9]/';

    /**
     * Verhoeff multiplication table (the dihedral group D5).
     *
     * @var array<int, array<int, int>>
     */
    private const VERHOEFF_D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /**
     * Verhoeff permutation table.
     *
     * @var array<int, array<int, int>>
     */
    private const VERHOEFF_P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /**
     * Canonicalise a submitted value to its digits, or null when nothing but
     * whitespace/ punctuation was sent. Non-scalar input (an array from a
     * hand-crafted request) is treated as "nothing submitted" — the shape rule
     * rejects it separately.
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    public static function isValid(?string $digits): bool
    {
        if ($digits === null || strlen($digits) !== self::LENGTH || ! ctype_digit($digits)) {
            return false;
        }

        if (preg_match(self::FIRST_DIGIT_PATTERN, $digits) !== 1) {
            return false;
        }

        return self::passesVerhoeff($digits);
    }

    /**
     * The last four digits — the only part ever stored or displayed in clear
     * text.
     */
    public static function last4(string $digits): string
    {
        return substr($digits, -4);
    }

    /**
     * Masked display for a stored tail: "1234" → "XXXX XXXX 1234".
     *
     * Only a real four-digit tail is rendered; anything else (null, blank, a
     * corrupted or truncated value) is "not recorded" rather than a masked
     * string that might expose part of the number.
     */
    public static function mask(?string $last4): ?string
    {
        $last4 = is_string($last4) ? preg_replace('/\D+/', '', $last4) : null;

        if ($last4 === null || strlen($last4) !== 4) {
            return null;
        }

        return 'XXXX XXXX '.$last4;
    }

    /**
     * Deterministic, keyed digest used for duplicate detection. Keyed (HMAC)
     * so the stored value is not a plain unsalted hash of a 12-digit space.
     */
    public static function hash(string $digits): string
    {
        return hash_hmac('sha256', $digits, (string) config('app.key'));
    }

    public static function encrypt(string $digits): string
    {
        return (string) SensitiveIdentity::encrypt($digits);
    }

    public static function decrypt(?string $payload): ?string
    {
        return SensitiveIdentity::decrypt($payload);
    }

    /**
     * Aadhaar's twelfth digit is the Verhoeff checksum of the eleven digits
     * before it; the whole number is valid when the running checksum of every
     * digit (right to left) lands on 0.
     */
    private static function passesVerhoeff(string $digits): bool
    {
        $checksum = 0;

        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $checksum = self::VERHOEFF_D[$checksum][self::VERHOEFF_P[$position % 8][(int) $digit]];
        }

        return $checksum === 0;
    }
}
