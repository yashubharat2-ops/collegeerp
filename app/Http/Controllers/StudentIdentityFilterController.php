<?php

namespace App\Http\Controllers;

use App\Domain\Student\Filters\StudentFilter;
use App\Domain\Student\Support\Aadhaar;
use App\Domain\Student\Support\StudentIdentityFilterStore;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Applies the permission-gated identity search (Aadhaar / ABC ID) without ever
 * putting a value in a URL.
 *
 * Flow:
 *  1. the shared Student Filter panel posts the two identity fields here (POST,
 *     CSRF-protected) together with the page it came from;
 *  2. the values are normalised — a 12-digit Aadhaar becomes the same keyed HMAC
 *     stored in `students.aadhaar_hash`, a 4-digit Aadhaar becomes a last-four
 *     match, an ABC ID is kept exactly — and handed to
 *     StudentIdentityFilterStore, which returns an opaque token;
 *  3. the user is redirected back to the same list with `?identity=<token>`
 *     (and every other active filter preserved). Only the token is in the URL,
 *     and it is bound to this user and this college with a short TTL.
 *
 * Authorization: `students.view` (the list itself) plus the configured
 * `students.view_sensitive_identity` permission. The same checks run again when
 * the token is resolved by StudentFilter, so a token can never widen access.
 *
 * Nothing submitted here is logged or echoed back; validation errors name the
 * field only, never the value.
 */
class StudentIdentityFilterController extends Controller
{
    public function __invoke(
        Request $request,
        StudentFilter $studentFilter,
        StudentIdentityFilterStore $store,
        TenantContext $tenant,
    ): RedirectResponse {
        $this->authorize('viewAny', Student::class);
        abort_unless($studentFilter->canFilterSensitiveIdentity($request->user()), 403);

        $validated = $request->validate([
            'return_to' => ['nullable', 'string', 'max:2000'],
            'identity' => ['nullable', 'string', 'max:64'],
            'aadhaar' => ['nullable', 'string', 'max:20'],
            'apaar_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\/\-]+$/'],
        ]);

        $target = $this->localPath($validated['return_to'] ?? null);
        $previous = $validated['identity'] ?? null;

        // Replacing or clearing an identity search retires the token it replaces.
        $store->forget(is_string($previous) ? $previous : null);

        $criteria = $this->criteria($validated);

        if ($criteria === []) {
            // Nothing sensitive supplied: drop the token and keep the rest of the
            // filters exactly as they were (this is the "clear identity search"
            // path of the same form).
            return redirect()->to($this->withToken($target, null))->with(
                'status',
                'Identity search cleared.'
            );
        }

        $token = $store->put($criteria, $request->user(), $tenant->id());

        return redirect()->to($this->withToken($target, $token))->with(
            'status',
            'Identity search applied. The value is kept on the server and is not shown in the address bar.'
        );
    }

    /**
     * Normalise the submitted identity values into canonical criteria.
     *
     * An Aadhaar of 12 digits is structurally validated (structure + Verhoeff)
     * and replaced by its HMAC before it leaves this method; a 4-digit value is a
     * last-four match. An invalid Aadhaar is reported as a validation error that
     * names the field and never repeats the digits.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function criteria(array $validated): array
    {
        $criteria = [];

        $aadhaar = Aadhaar::normalise($validated['aadhaar'] ?? null);
        if ($aadhaar !== null) {
            if (strlen($aadhaar) === 4) {
                $criteria['aadhaar_last4'] = $aadhaar;
            } else {
                if (! Aadhaar::isValid($aadhaar)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'aadhaar' => 'Enter a valid 12-digit Aadhaar number, or its last 4 digits.',
                    ]);
                }

                // Only the keyed digest is kept: it is an exact match on the
                // whole number and nothing else about the number is stored.
                $criteria['aadhaar_hash'] = Aadhaar::hash($aadhaar);
            }
        }

        $apaar = isset($validated['apaar_id']) ? trim((string) $validated['apaar_id']) : '';
        if ($apaar !== '') {
            $criteria['apaar_id'] = $apaar;
        }

        return $criteria;
    }

    /**
     * Only a path on this application is accepted as a redirect target, so the
     * form can never be used as an open redirect.
     *
     * The path part is validated strictly (a single leading slash, no host, no
     * scheme, no `//`); the query string is left as the browser sent it and is
     * rebuilt with http_build_query() in withToken(), so filter values such as
     * `academic_context=section:5` survive.
     */
    private function localPath(?string $returnTo): string
    {
        $fallback = route('students.index', absolute: false);
        $candidate = is_string($returnTo) ? trim($returnTo) : '';

        if ($candidate === '' || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#^/[A-Za-z0-9\-_./]*$#D', $path) !== 1) {
            return $fallback;
        }

        return $candidate;
    }

    /**
     * Replace the token in a local URL, dropping `page` (a new search starts at
     * page 1) and any previous token.
     */
    private function withToken(string $path, ?string $token): string
    {
        $parts = parse_url($path);
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        unset($query['identity'], $query['page']);

        if ($token !== null) {
            $query['identity'] = $token;
        }

        $base = $parts['path'] ?? '/';
        $queryString = http_build_query($query);

        return $queryString === '' ? $base : $base.'?'.$queryString;
    }
}
