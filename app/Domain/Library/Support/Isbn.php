<?php

namespace App\Domain\Library\Support;

/**
 * Isbn — normalisation and format validation for book ISBNs.
 *
 * ISBNs are printed with hyphens or spaces in publisher-specific positions
 * ("978-0-13-468599-1", "0 13 468599 X"), so the same number can be typed many
 * ways. The library stores the canonical form — digits only, plus a trailing
 * "X" check character for ISBN-10 — and validates against it, which is what
 * makes the per-college uniqueness rule meaningful.
 *
 * Only the structure is validated (10 or 13 characters, ISBN-13 prefixed 978 or
 * 979). The check digit is deliberately not enforced: misprinted check digits
 * exist on real books and librarians must still be able to catalogue them.
 */
final class Isbn
{
    /**
     * Canonical storage form, or null when nothing usable was supplied.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[\s\-]+/', '', trim((string) $value)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Whether the (already normalized) value has the structure of an ISBN-10 or
     * an ISBN-13.
     */
    public static function isValid(?string $normalized): bool
    {
        if ($normalized === null || $normalized === '') {
            return false;
        }

        return (bool) preg_match('/^(?:\d{9}[\dX]|97[89]\d{10})$/', $normalized);
    }
}
