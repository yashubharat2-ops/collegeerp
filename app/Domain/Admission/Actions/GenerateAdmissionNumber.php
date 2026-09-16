<?php

namespace App\Domain\Admission\Actions;

use App\Models\Admission;
use App\Models\College;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique admission number per college, safe under concurrent requests.
 *
 * Format: ADM-{YEAR}-{SEQ} e.g. ADM-2026-0001
 * YEAR from academic year code or current year.
 */
class GenerateAdmissionNumber
{
    public function execute(int $collegeId, ?string $academicYearCode = null): string
    {
        return DB::transaction(function () use ($collegeId, $academicYearCode): string {
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart = $academicYearCode ?? date('Y');

            $existingNumbers = Admission::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->pluck('admission_number');

            $maxSeq = 0;
            foreach ($existingNumbers as $num) {
                if (preg_match('/(\d{4,})$/', $num, $m)) {
                    $seq = (int) $m[1];
                    if ($seq > $maxSeq) {
                        $maxSeq = $seq;
                    }
                }
            }

            $nextSeq = $maxSeq + 1;

            $attempts = 0;
            do {
                $candidate = sprintf('ADM-%s-%04d', $yearPart, $nextSeq + $attempts);
                $exists = Admission::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('admission_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            if ($exists) {
                $candidate = sprintf('ADM-%s-%04d-%s', $yearPart, $nextSeq, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }
}
