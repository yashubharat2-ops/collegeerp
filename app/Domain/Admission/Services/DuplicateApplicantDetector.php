<?php

namespace App\Domain\Admission\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\AdmissionApplicant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Simple, reliable same-college duplicate detection.
 *
 * Signals: phone, email
 * - Searches same college only (tenant isolation) via explicit college_id filter
 * - Never returns cross-college records
 * - Does not treat match as automatic identity proof — UI should warn and allow reuse
 *
 * Note: Uses withoutGlobalScope(CollegeScope::class) to avoid double-filtering via TenantContext
 * while still enforcing tenant isolation explicitly via college_id. This allows the service to be
 * used both in HTTP context (where TenantContext is set) and in direct service calls/tests
 * (where TenantContext may not be set). SoftDeletes scope is retained — deleted applicants are not returned.
 */
class DuplicateApplicantDetector
{
    /**
     * @return Collection<AdmissionApplicant>
     */
    public function detect(int $collegeId, ?string $phone, ?string $email, int $limit = 5): Collection
    {
        $phone = trim((string) $phone);
        $email = trim((string) $email);

        if ($phone === '' && $email === '') {
            return new Collection();
        }

        // Bypass CollegeScope global scope and rely on explicit college_id for tenant isolation.
        // This prevents the query from returning 0 rows when TenantContext is not set (e.g., in tests
        // or jobs) while still ensuring cross-college data never leaks.
        $query = AdmissionApplicant::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->orderBy('created_at', 'desc');

        $query->where(function ($q) use ($phone, $email): void {
            if ($phone !== '') {
                $q->orWhere('phone', $phone)
                  ->orWhere('alternate_phone', $phone);
            }
            if ($email !== '') {
                $q->orWhere('email', $email);
            }
        });

        return $query->limit($limit)->get();
    }
}
