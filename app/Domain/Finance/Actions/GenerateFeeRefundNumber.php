<?php

namespace App\Domain\Finance\Actions;

use App\Models\College;
use App\Models\FeeRefund;
use Illuminate\Support\Facades\DB;

/**
 * Generates a unique refund number per college, safe under concurrent requests.
 *
 * Format: REF-{YEAR}-{SEQ} e.g. REF-2026-0001. The college row is locked for
 * update to serialize generation per college (same approach as every other
 * number generator in the project), so two concurrent refunds cannot collide.
 *
 * The number is always server-generated and is never accepted from the client.
 */
class GenerateFeeRefundNumber
{
    public function execute(int $collegeId, ?string $yearPart = null): string
    {
        return DB::transaction(function () use ($collegeId, $yearPart): string {
            College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();

            $yearPart ??= date('Y');
            $prefix = 'REF-'.$yearPart.'-';

            $maxSeq = $this->highestSequence($collegeId, $prefix);
            $attempts = 0;
            $candidate = '';

            do {
                $candidate = sprintf('%s%04d', $prefix, $maxSeq + 1 + $attempts);
                $exists = FeeRefund::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('refund_number', $candidate)
                    ->exists();
                $attempts++;
            } while ($exists && $attempts < 100);

            if ($exists) {
                $candidate = sprintf('%s%04d-%s', $prefix, $maxSeq + 1, substr(uniqid(), -4));
            }

            return $candidate;
        });
    }

    private function highestSequence(int $collegeId, string $prefix): int
    {
        $numbers = FeeRefund::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('refund_number', 'like', $prefix.'%')
            ->pluck('refund_number');

        $maxSeq = 0;

        foreach ($numbers as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)/', (string) $number, $matches)) {
                $maxSeq = max($maxSeq, (int) $matches[1]);
            }
        }

        return $maxSeq;
    }
}
