<?php

namespace App\Domain\Admission\Actions;

use App\Models\AdmissionApplication;
use App\Models\College;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique application number per college, safe under concurrent requests.
 *
 * Format: APP-{YEAR}-{SEQ} e.g. APP-2026-0001
 * YEAR is taken from the academic year code (required for applications),
 * otherwise the current year. SEQ is zero-padded 4 digits, sequential per
 * college within the academic year context.
 *
 * Strategy (mirrors GenerateEnquiryNumber):
 * - Lock college row for update to serialize number generation per college
 *   (same approach as the AcademicYear overlap check).
 * - Find max existing sequence for this college + academic year and increment.
 * - Uses transaction-safe locking, never a bare MAX()+1 without a lock.
 *
 * No counter table: the college row lock is sufficient and matches the
 * existing architecture.
 */
class GenerateApplicationNumber
{
    public function execute(int $collegeId, ?int $academicYearId = null, ?string $academicYearCode = null): string
    {
        return DB::transaction(function () use ($collegeId, $academicYearId, $academicYearCode): string {
            // Lock college row to serialize per college
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart = $academicYearCode ?? date('Y');

            // Sequential within the college/year context: only numbers for this
            // college (and academic year when known) participate in SEQ calc.
            $query = AdmissionApplication::withoutGlobalScopes()
                ->where('college_id', $collegeId);

            if ($academicYearId) {
                $query->where('academic_year_id', $academicYearId);
            }

            $existingNumbers = $query->pluck('application_number');

            $maxSeq = 0;
            foreach ($existingNumbers as $num) {
                // Expect format APP-YYYY-NNNN
                if (preg_match('/(\d{4,})$/', $num, $m)) {
                    $seq = (int) $m[1];
                    if ($seq > $maxSeq) {
                        $maxSeq = $seq;
                    }
                }
            }

            $nextSeq = $maxSeq + 1;

            // Ensure uniqueness even under race: loop if collision (should not
            // happen due to lock, but safe)
            $attempts = 0;
            do {
                $candidate = sprintf('APP-%s-%04d', $yearPart, $nextSeq + $attempts);
                $exists = AdmissionApplication::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('application_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            if ($exists) {
                // Fallback to random suffix if still collides (extremely unlikely)
                $candidate = sprintf('APP-%s-%04d-%s', $yearPart, $nextSeq, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }
}
