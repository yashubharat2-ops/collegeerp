<?php

namespace App\Domain\Communication\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Defensive parsing of listing filters (query-string input).
 *
 * Filters never fail a request: anything malformed (arrays, unknown values,
 * impossible dates) is simply ignored, so a hand-edited URL can neither
 * error the page nor widen a query beyond the tenant scope.
 */
final class CommunicationFilters
{
    public static function text(mixed $value, int $max = 100): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function choice(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** A normalised type slug, or null. */
    public static function type(mixed $value): ?string
    {
        $normalized = CommunicationTypes::normalize($value);

        return is_string($normalized) && $normalized !== '' && preg_match(CommunicationTypes::PATTERN, $normalized)
            ? mb_substr($normalized, 0, CommunicationTypes::MAX_LENGTH)
            : null;
    }

    /** A strict Y-m-d date, or null. */
    public static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
