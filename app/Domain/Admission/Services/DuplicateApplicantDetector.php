<?php

namespace App\Domain\Admission\Services;

use App\Models\AdmissionApplicant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Simple, reliable same-college duplicate detection.
 *
 * Signals: phone, email
 * - Searches same college only (tenant isolation)
 * - Never returns cross-college records
 * - Does not treat match as automatic identity proof — UI should warn and allow reuse
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

        $query = AdmissionApplicant::query()
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
