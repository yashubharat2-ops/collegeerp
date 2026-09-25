<?php

namespace App\Support\Tenancy;

use Illuminate\Contracts\Session\Session;

/**
 * One-shot notifications belong to the tenant that produced them.
 *
 * A flashed message (or a validation error bag, or the previous form input)
 * lives in the session, which outlives a single request — and it may outlive
 * the tenant it was written under. Because the session is per *user*, not per
 * college, a message composed from one college's records would otherwise be
 * rendered on the next page the user opens in a different college: the stock
 * ledger of college A would carry a notification quoting an item that only
 * exists in college B.
 *
 * The fix is to remember which college wrote the pending flash and drop it the
 * moment a request is served under a different one. Nothing persistent is
 * touched: only the one-shot keys below, and only across a tenant change.
 *
 * A deliberate college switch is not a "stale" flash — that is the authorized
 * boundary and its own confirmation message belongs to the college being
 * entered, so CollegeSwitchController stamps the new college itself.
 */
final class TenantFlash
{
    /** Session key holding the college the pending one-shot data was written under. */
    public const TENANT_KEY = 'tenant_context_college_id';

    /**
     * Keys that carry text composed from a tenant's own records, plus the
     * repopulation state of the form that produced them.
     *
     * @var list<string>
     */
    private const ONE_SHOT_KEYS = [
        'success',
        'error',
        'warning',
        'info',
        'status',
        'errors',
        '_old_input',
    ];

    /**
     * Drop pending one-shot data written under another college, then record
     * that $collegeId owns whatever is flashed from here on.
     */
    public static function forgetStale(Session $session, int $collegeId): void
    {
        $previous = $session->get(self::TENANT_KEY);

        if ($previous !== null && (int) $previous !== $collegeId) {
            $session->forget(self::ONE_SHOT_KEYS);
        }

        self::stamp($session, $collegeId);
    }

    /**
     * Record $collegeId as the tenant that owns the pending one-shot data,
     * without discarding it. Used at an authorized tenant boundary (a college
     * switch) so that boundary's own confirmation survives the change.
     */
    public static function stamp(Session $session, int $collegeId): void
    {
        $session->put(self::TENANT_KEY, $collegeId);
    }
}
