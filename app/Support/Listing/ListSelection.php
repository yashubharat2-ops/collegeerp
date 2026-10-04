<?php

namespace App\Support\Listing;

/**
 * Server-side sanitising of a client-supplied record selection.
 *
 * A listing page may hand the ids the user ticked to the server (export, batch
 * print, bulk action). Those ids are treated as a *request*, never as data:
 *
 *  - only positive integers survive (`ctype_digit`), so nothing but ids can
 *    reach a query;
 *  - duplicates collapse, so a 1 000-times repeated id is one row;
 *  - the list is capped, so a hand-crafted request cannot push an unbounded
 *    `whereIn` (or an unbounded in-memory collection) at the application.
 *
 * Everything else — tenant scope and per-record authorization — is enforced by
 * the code that runs the query (CollegeScope, policies, BulkActionHandler), so
 * this class is deliberately only about shape, never about permission.
 */
final class ListSelection
{
    /**
     * Hard cap on one selection. The listing pages select at most one page of
     * rows (100 with the widest page size), so this is head-room, not a limit a
     * real interaction can reach.
     */
    public const DEFAULT_LIMIT = 200;

    /**
     * Normalise a raw `ids` input (array, comma-separated string or null).
     *
     * @return array<int, int> Unique positive ids, in first-seen order, capped at $limit.
     */
    public static function ids(mixed $raw, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, $limit);

        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($items as $item) {
            if (is_array($item) || is_bool($item) || $item === null) {
                continue;
            }

            $value = trim((string) $item);

            if ($value === '' || ! ctype_digit($value)) {
                continue;
            }

            $ids[(int) $value] = true;

            if (count($ids) >= $limit) {
                break;
            }
        }

        return array_keys($ids);
    }
}
