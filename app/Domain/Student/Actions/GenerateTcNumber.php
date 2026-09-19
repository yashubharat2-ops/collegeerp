<?php

namespace App\Domain\Student\Actions;

use App\Models\College;
use App\Models\StudentTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique Transfer Certificate number per college.
 *
 * Format: TC-{YEAR}-{SEQ} e.g. TC-2026-0001
 *
 * YEAR comes from the TC issue date when known, otherwise the current calendar
 * year; SEQ is a zero-padded 4-digit sequence that restarts per year context
 * within the college. The college row is locked for update to serialise
 * generation per college — exactly the scheme used by GenerateStudentNumber,
 * GenerateEnrollmentNumber and the admission number generators — so two
 * concurrent issuances cannot mint the same number.
 *
 * The number is always server-generated and never trusted from the client. As
 * with the other generators, the prefix/shape is a parameterisable convention,
 * not an institution rule: the table stores the full string.
 */
class GenerateTcNumber
{
    public function execute(int $collegeId, ?string $yearPart = null): string
    {
        return DB::transaction(function () use ($collegeId, $yearPart): string {
            // Lock the college row to serialise number generation per college.
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart = $yearPart ?: date('Y');

            // SEQ is computed only from this college's numbers carrying the
            // current year segment, so numbering restarts per year context.
            $existingNumbers = StudentTransfer::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNotNull('tc_number')
                ->pluck('tc_number');

            $maxSeq = 0;
            foreach ($existingNumbers as $number) {
                if (! str_starts_with($number, 'TC-'.$yearPart.'-')) {
                    continue;
                }

                if (preg_match('/(\d{4,})$/', $number, $matches)) {
                    $maxSeq = max($maxSeq, (int) $matches[1]);
                }
            }

            $nextSeq = $maxSeq + 1;

            $attempts = 0;
            $candidate = '';
            $exists = false;
            do {
                $candidate = sprintf('TC-%s-%04d', $yearPart, $nextSeq + $attempts);
                $exists = StudentTransfer::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('tc_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            // Extremely unlikely fallback under pathological collisions.
            if ($exists) {
                $candidate = sprintf('TC-%s-%04d-%s', $yearPart, $nextSeq, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }
}
