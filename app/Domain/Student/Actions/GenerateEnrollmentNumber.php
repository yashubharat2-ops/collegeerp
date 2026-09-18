<?php

namespace App\Domain\Student\Actions;

use App\Models\College;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique enrollment number per college, safe under concurrent
 * requests.
 *
 * Format: ENR-{YEAR}-{SEQ} e.g. ENR-2026-0001
 *
 * YEAR is taken from the enrollment academic-year code (required for
 * enrollments), otherwise the current calendar year. SEQ is zero-padded,
 * sequential per college within that year context. The college row is locked
 * for update to serialize per-college generation, matching the existing
 * admission number generators.
 *
 * The number is always server-generated and never trusted from the client.
 */
class GenerateEnrollmentNumber
{
    public function execute(int $collegeId, ?string $academicYearCode = null): string
    {
        return DB::transaction(function () use ($collegeId, $academicYearCode): string {
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart = $academicYearCode ?? date('Y');

            $existingNumbers = StudentEnrollment::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->pluck('enrollment_number');

            $maxSeq = 0;
            foreach ($existingNumbers as $number) {
                if (! str_starts_with($number, 'ENR-'.$yearPart.'-')) {
                    continue;
                }

                if (preg_match('/(\d{4,})$/', $number, $matches)) {
                    $maxSeq = max($maxSeq, (int) $matches[1]);
                }
            }

            $nextSeq = $maxSeq + 1;

            $attempts = 0;
            $candidate = '';
            do {
                $candidate = sprintf('ENR-%s-%04d', $yearPart, $nextSeq + $attempts);
                $exists = StudentEnrollment::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('enrollment_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            if ($exists) {
                $candidate = sprintf('ENR-%s-%04d-%s', $yearPart, $nextSeq, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }
}
