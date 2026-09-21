<?php

namespace App\Domain\Finance\Actions;

use App\Models\College;
use App\Models\FeePayment;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique fee payment number per college, safe under concurrent requests.
 *
 * Format: PAY-{YEAR}-{SEQ} e.g. PAY-2026-0001 — YEAR is the current calendar
 * year and SEQ is a zero-padded sequence inside that year, per college. The
 * college row is locked for update to serialize generation per college, the same
 * approach used by the student/admission/enrollment number generators, so two
 * concurrent collections can never mint the same number.
 *
 * The number is always server-generated and is never accepted from the client.
 * The shape is parameterisable rather than an institution rule: only the prefix
 * and the zero-padding are fixed here.
 */
class GenerateFeePaymentNumber
{
    public function execute(int $collegeId, ?string $yearPart = null): string
    {
        return DB::transaction(function () use ($collegeId, $yearPart): string {
            // Lock the college row to serialize number generation per college.
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart ??= date('Y');
            $prefix = 'PAY-'.$yearPart.'-';

            $maxSeq = $this->highestSequence($collegeId, $prefix);
            $attempts = 0;
            $candidate = '';

            do {
                $candidate = sprintf('%s%04d', $prefix, $maxSeq + 1 + $attempts);
                $exists = FeePayment::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('payment_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            // Extremely unlikely fallback under pathological collisions.
            if ($exists) {
                $candidate = sprintf('%s%04d-%s', $prefix, $maxSeq + 1, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }

    private function highestSequence(int $collegeId, string $prefix): int
    {
        $numbers = FeePayment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('payment_number', 'like', $prefix.'%')
            ->pluck('payment_number');

        $maxSeq = 0;

        foreach ($numbers as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)/', (string) $number, $matches)) {
                $maxSeq = max($maxSeq, (int) $matches[1]);
            }
        }

        return $maxSeq;
    }
}
