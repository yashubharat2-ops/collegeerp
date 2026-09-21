<?php

namespace App\Services\Examinations;

use App\Models\ExamResult;
use App\Models\Examination;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ResultPublishingService — the controlled publication workflow
 * (Examinations Phase 3).
 *
 * Publishing is deliberately a SEPARATE step from calculation:
 *
 *   Draft / Calculated → Ready for Publishing → Published
 *
 * A result never becomes published merely because a calculation completed.
 * Everything here is transactional — a bulk publish either publishes every
 * eligible result or none of them.
 *
 * Publication state is stored as published_at + published_by (never a separate
 * flag that could drift), and the derived publication status is exposed by
 * ExamResult::$publication_status.
 *
 * Tenant safety: results are always loaded through CollegeScope under the
 * ACTIVE tenant context, so a browser-supplied result id from another college
 * can never be published.
 */
class ResultPublishingService
{
    public function __construct(
        private readonly ResultRuleService $rules,
        private readonly AuditLogService $audit,
    ) {
    }

    public function publish(ExamResult $result, User $actor): ExamResult
    {
        $this->assertPublishable($result);

        return DB::transaction(function () use ($result, $actor): ExamResult {
            $old = ['published_at' => $result->published_at?->toDateTimeString(), 'published_by' => $result->published_by];

            $result->forceFill([
                'published_at' => now(),
                'published_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('results.published', $result, $old, [
                'published_at' => $result->published_at?->toDateTimeString(),
                'published_by' => $result->published_by,
            ]);

            return $result->refresh();
        });
    }

    public function unpublish(ExamResult $result, User $actor): ExamResult
    {
        return DB::transaction(function () use ($result, $actor): ExamResult {
            $old = ['published_at' => $result->published_at?->toDateTimeString(), 'published_by' => $result->published_by];

            $result->forceFill([
                'published_at' => null,
                'published_by' => null,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('results.unpublished', $result, $old, [
                'published_at' => null,
                'published_by' => null,
            ]);

            return $result->refresh();
        });
    }

    /**
     * Transactional bulk publish: every eligible result is published, or none
     * is. Ineligible results are reported back instead of silently skipped.
     *
     * @param  Collection<int, ExamResult>  $results
     * @return array{published: int, skipped: int, skipped_ids: list<int>}
     */
    public function publishMany(Collection $results, User $actor): array
    {
        return DB::transaction(function () use ($results, $actor): array {
            $published = 0;
            $skippedIds = [];

            foreach ($results as $result) {
                if (! $this->isPublishable($result)) {
                    $skippedIds[] = (int) $result->getKey();

                    continue;
                }

                $result->forceFill([
                    'published_at' => now(),
                    'published_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                $published++;
            }

            if ($published === 0 && $skippedIds !== []) {
                throw ValidationException::withMessages([
                    'result_ids' => 'None of the selected results are eligible for publishing.',
                ]);
            }

            $this->audit->record('results.bulk_published', null, [], [
                'published' => $published,
                'skipped' => count($skippedIds),
            ]);

            return [
                'published' => $published,
                'skipped' => count($skippedIds),
                'skipped_ids' => $skippedIds,
            ];
        });
    }

    /**
     * Publish every eligible calculated result of one examination.
     *
     * @return array{published: int, skipped: int, skipped_ids: list<int>}
     */
    public function publishExamination(Examination $examination, User $actor): array
    {
        $results = ExamResult::query()
            ->where('examination_id', $examination->getKey())
            ->where('calculation_status', ExamResult::CALCULATION_CALCULATED)
            ->whereNull('published_at')
            ->orderBy('id')
            ->get();

        return $this->publishMany($results, $actor);
    }

    public function isPublishable(ExamResult $result): bool
    {
        return $this->rules->isPublishableStatus($result->result_status, $result->calculation_status)
            && $this->rules->hasUsableGradeConfiguration($result);
    }

    /**
     * Human-readable reason a result cannot be published (used by the UI and
     * by the tests' failure messages).
     */
    public function publishBlockedReason(ExamResult $result): ?string
    {
        if ($result->calculation_status === ExamResult::CALCULATION_FAILED) {
            return 'The captured marks failed validation; recalculate after fixing them.';
        }

        if ($result->calculation_status === ExamResult::CALCULATION_PENDING) {
            return 'This result has not been calculated yet.';
        }

        if ($result->calculation_status === ExamResult::CALCULATION_INCOMPLETE) {
            return 'Required marks are still missing or in draft; the result is incomplete.';
        }

        if ($result->result_status === ExamResult::RESULT_INCOMPLETE) {
            return 'Required marks are still missing; the result is incomplete.';
        }

        if (! $this->rules->hasUsableGradeConfiguration($result)) {
            return 'The grading configuration behind this result is invalid.';
        }

        return null;
    }

    private function assertPublishable(ExamResult $result): void
    {
        $reason = $result->isPublished()
            ? 'This result is already published.'
            : $this->publishBlockedReason($result);

        if ($reason !== null) {
            throw ValidationException::withMessages(['result' => $reason]);
        }
    }
}
