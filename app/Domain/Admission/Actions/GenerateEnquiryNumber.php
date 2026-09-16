<?php

namespace App\Domain\Admission\Actions;

use App\Models\AdmissionEnquiry;
use App\Models\College;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique enquiry number per college, safe under concurrent requests.
 *
 * Format: ENQ-{YEAR}-{SEQ} e.g. ENQ-2026-0001
 * YEAR is taken from academic year code if available, otherwise current year.
 * SEQ is zero-padded 4 digits, sequential per college (and per academic year if provided).
 *
 * Strategy:
 * - Lock college row for update to serialize number generation per college (similar to AcademicYear overlap check).
 * - Find max existing number for college (and academic year if provided) and increment.
 * - Uses transaction-safe locking, no simple MAX()+1 without lock.
 *
 * No counter table needed for foundation; college row lock is sufficient and matches existing
 * architecture (AcademicYear uses College::lockForUpdate()).
 */
class GenerateEnquiryNumber
{
    public function execute(int $collegeId, ?int $academicYearId = null, ?string $academicYearCode = null): string
    {
        return DB::transaction(function () use ($collegeId, $academicYearId, $academicYearCode): string {
            // Lock college row to serialize per college
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart = $academicYearCode ?? date('Y');

            // Find existing numbers for this college that match pattern ENQ-{yearPart}-*
            // We parse SEQ from existing numbers to increment.
            // For safety, we look for max number overall for college, not just year, to avoid collisions
            // if format changes, but we prefer year-scoped if academicYearId provided.

            $query = AdmissionEnquiry::withoutGlobalScopes()
                ->where('college_id', $collegeId);

            if ($academicYearId) {
                $query->where('academic_year_id', $academicYearId);
            }

            $existingNumbers = $query->pluck('enquiry_number');

            $maxSeq = 0;
            foreach ($existingNumbers as $num) {
                // Expect format ENQ-YYYY-NNNN or ENQ-NNNN
                if (preg_match('/(\d{4,})$/', $num, $m)) {
                    $seq = (int) $m[1];
                    if ($seq > $maxSeq) {
                        $maxSeq = $seq;
                    }
                }
            }

            $nextSeq = $maxSeq + 1;

            // Ensure uniqueness even under race: loop if collision (should not happen due to lock, but safe)
            $attempts = 0;
            do {
                $candidate = sprintf('ENQ-%s-%04d', $yearPart, $nextSeq + $attempts);
                $exists = AdmissionEnquiry::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('enquiry_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            if ($exists) {
                // Fallback to random suffix if still collides (extremely unlikely)
                $candidate = sprintf('ENQ-%s-%04d-%s', $yearPart, $nextSeq, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }
}
