<?php

namespace App\Domain\Admission\BulkActions;

use App\Domain\Admission\Services\AdmissionApplicationStatusService;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk review of admission applications: move selected applications to a review
 * outcome (under_review, approved or rejected).
 *
 * - Only the statuses in {@see self::REVIEW_TARGETS} can be set in bulk. Admission
 *   (`admitted`) is deliberately excluded: admitting stays a single-record action.
 * - Every change goes through {@see AdmissionApplicationStatusService}, the same
 *   check the single-record edit uses (workflow map and server-side
 *   `submitted_at`). A row whose transition is not workflow-approved is skipped.
 * - Each row is re-read under a row lock before the decision.
 * - One audit entry per changed row (`admission_application.updated`).
 */
class AdmissionApplicationBulkReviewHandler extends BulkActionHandler
{
    /** Review outcomes a bulk action may set. `admitted`, `draft` and `cancelled` are excluded. */
    public const REVIEW_TARGETS = ['under_review', 'approved', 'rejected'];

    public function __construct(
        private readonly AdmissionApplicationStatusService $statuses,
        private readonly AuditLogService $audit,
    ) {}

    public function modelClass(): string
    {
        return AdmissionApplication::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_applications.update';
    }

    public function policyAbility(): ?string
    {
        return 'update';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $target = $parameters['status'] ?? null;

        if (! is_string($target) || ! in_array($target, self::REVIEW_TARGETS, true)) {
            return BulkActionResult::failed('Choose a valid review outcome: under review, approved or rejected.');
        }

        $changed = 0;
        $blocked = 0;

        try {
            DB::transaction(function () use ($records, $college, $target, $user, &$changed, &$blocked): void {
                // Deterministic lock order (ascending id) across overlapping bulk requests.
                foreach ($records->sortBy('id') as $record) {
                    $application = AdmissionApplication::withoutGlobalScopes()
                        ->where('college_id', $college->id)
                        ->whereNull('deleted_at')
                        ->whereKey($record->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $application || $application->status === $target) {
                        continue;
                    }

                    if (! $this->statuses->canTransition($application->status, $target)) {
                        $blocked++;

                        continue;
                    }

                    $old = $application->only(['status', 'submitted_at']);
                    $attributes = $this->statuses->attributesForStatus($application, $target, ['updated_by' => $user->id]);

                    $application->update($attributes);
                    $this->audit->record('admission_application.updated', $application, $old, $application->only(['status', 'submitted_at']));
                    $changed++;
                }
            });
        } catch (Throwable) {
            return BulkActionResult::failed('The applications could not be reviewed. No records were changed.');
        }

        if ($changed === 0 && $blocked > 0) {
            return BulkActionResult::failed(
                'None of the selected applications can move to that status under the admission workflow. Nothing was changed.'
            );
        }

        $message = $changed === 1 ? '1 application updated.' : $changed.' applications updated.';

        if ($blocked > 0) {
            $message .= ' '.$blocked.' '.($blocked === 1 ? 'was' : 'were').' skipped because that status change is not allowed by the admission workflow.';
        }

        return BulkActionResult::success($message, $changed);
    }
}
