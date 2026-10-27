<?php

namespace App\Domain\Student\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side vault for the permission-gated identity searches (Aadhaar / ABC ID).
 *
 * Why this exists
 * ---------------
 * Aadhaar and ABC/APAAR numbers must never appear in a URL — not in the address
 * bar, not in browser history, not in a proxy/server access log, not in a
 * Referer header. A normal GET filter form would put them in the query string,
 * so the shared Student Filter submits the two identity fields by POST instead:
 *
 *   POST students/filter/identity  →  store the criteria, mint an opaque token
 *                                  →  redirect to the list with `?identity=<token>`
 *
 * The GET page then carries only the opaque token, which this class resolves
 * back to criteria for the signed-in user in the active college. The token is
 * therefore useless to anyone else (it is bound to the user id), to another
 * college (bound to the college id) and after a short TTL.
 *
 * What is stored
 * --------------
 *  - `aadhaar_hash`   : the same keyed HMAC already stored in
 *                       `students.aadhaar_hash`, so an exact lookup never needs
 *                       to decrypt a row and the vault never holds a full number.
 *  - `aadhaar_last4`  : the four digits the user typed, which is what the
 *                       existing masked display shows anyway.
 *  - `apaar_id`       : the ABC ID, encrypted with the application key
 *                       (Crypt) because the column itself is plain text and the
 *                       value is needed for an exact match.
 *
 * The session driver is server-side (file/database/redis); encrypted values stay
 * encrypted even if a session file is read, and nothing is ever logged here.
 */
final class StudentIdentityFilterStore
{
    /** Canonical criteria keys this vault accepts. */
    public const KEYS = ['aadhaar_hash', 'aadhaar_last4', 'apaar_id'];

    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{32,64}$/D';

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Store the criteria for this user/college and return the opaque token.
     *
     * @param  array<string, string>  $criteria  canonical keys only
     */
    public function put(array $criteria, User $user, ?int $collegeId): string
    {
        $token = Str::random($this->tokenLength());
        $entries = $this->entries();
        $entries[$token] = [
            'user_id' => (int) $user->getKey(),
            'college_id' => $collegeId,
            'expires_at' => now()->addMinutes($this->ttlMinutes())->getTimestamp(),
            'criteria' => $this->encryptCriteria($criteria),
        ];

        $this->session->put($this->sessionKey(), $entries);

        return $token;
    }

    /**
     * Resolve a token to canonical criteria, or an empty array.
     *
     * A token belonging to another user, another college, or an expired entry is
     * treated exactly like an unknown token: no filter is applied (the ordinary
     * list is shown) and nothing is disclosed about the entry.
     *
     * @return array<string, string>
     */
    public function resolve(?string $token, ?User $user, ?int $collegeId): array
    {
        if ($token === null || $user === null || ! preg_match(self::TOKEN_PATTERN, $token)) {
            return [];
        }

        $entries = $this->entries();
        $entry = $entries[$token] ?? null;

        if (! is_array($entry)) {
            return [];
        }

        if ((int) ($entry['user_id'] ?? 0) !== (int) $user->getKey()) {
            return [];
        }

        if (($entry['college_id'] ?? null) !== $collegeId) {
            return [];
        }

        if ((int) ($entry['expires_at'] ?? 0) <= now()->getTimestamp()) {
            unset($entries[$token]);
            $this->session->put($this->sessionKey(), $entries);

            return [];
        }

        return $this->decryptCriteria((array) ($entry['criteria'] ?? []));
    }

    /** Forget one token (a cleared or replaced identity search). */
    public function forget(?string $token): void
    {
        if ($token === null || ! preg_match(self::TOKEN_PATTERN, $token)) {
            return;
        }

        $entries = $this->entries();
        if (! isset($entries[$token])) {
            return;
        }

        unset($entries[$token]);
        $this->session->put($this->sessionKey(), $entries);
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(): array
    {
        $entries = $this->session->get($this->sessionKey(), []);
        if (! is_array($entries)) {
            return [];
        }

        $now = now()->getTimestamp();
        $live = [];
        foreach ($entries as $token => $entry) {
            if (! is_string($token) || ! is_array($entry)) {
                continue;
            }
            if ((int) ($entry['expires_at'] ?? 0) <= $now) {
                continue;
            }
            $live[$token] = $entry;
        }

        return $live;
    }

    /**
     * @param  array<string, string>  $criteria
     * @return array<string, string>
     */
    private function encryptCriteria(array $criteria): array
    {
        $stored = [];

        foreach (self::KEYS as $key) {
            $value = $criteria[$key] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $stored[$key] = $key === 'apaar_id'
                ? Crypt::encryptString($value)
                : $value;
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, string>
     */
    private function decryptCriteria(array $stored): array
    {
        $criteria = [];

        foreach (self::KEYS as $key) {
            $value = $stored[$key] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($key === 'apaar_id') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (Throwable) {
                    continue;
                }
            }

            $criteria[$key] = $value;
        }

        return $criteria;
    }

    private function sessionKey(): string
    {
        return (string) config('student-filter.identity.session_key', 'student.filter.identity');
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('student-filter.identity.ttl_minutes', 60));
    }

    private function tokenLength(): int
    {
        return min(64, max(32, (int) config('student-filter.identity.token_length', 40)));
    }
}
