<?php

namespace App\Domain\Admission\BulkActions;

use App\Models\AdmissionEnquiry;
use App\Models\College;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk status change for admission enquiries.
 *
 * - The target status is a validated parameter, limited to
 *   {@see AdmissionEnquiry::BULK_TARGET_STATUSES}. `converted` is never a target.
 * - A row changes only along {@see AdmissionEnquiry::BULK_TRANSITIONS}. A row
 *   whose current status has no transition to the target (including a converted
 *   enquiry) is skipped and reported, not changed.
 * - Each row is re-read under a row lock inside the transaction, so the decision
 *   is made on committed state.
 * - One audit entry per changed row (`admission_enquiry.updated`, status only).
 */
class AdmissionEnquiryBulkStatusHandler extends BulkActionHandler
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function modelClass(): string
    {
        return AdmissionEnquiry::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_enquiries.update';
    }

    public function policyAbility(): ?string
    {
        return 'update';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $target = $parameters['status'] ?? null;

        if (! is_string($target) || ! in_array($target, AdmissionEnquiry::BULK_TARGET_STATUSES, true)) {
            return BulkActionResult::failed('Choose a valid enquiry status. Converted enquiries cannot be set in bulk.');
        }

        $changed = 0;
        $blocked = 0;

        try {
            DB::transaction(function () use ($records, $college, $target, $user, &$changed, &$blocked): void {
                // Deterministic lock order (ascending id) across overlapping bulk requests.
                foreach ($records->sortBy('id') as $record) {
                    // Re-read under lock: the status decision uses committed state.
                    $enquiry = AdmissionEnquiry::withoutGlobalScopes()
                        ->where('college_id', $college->id)
                        ->whereNull('deleted_at')
                        ->whereKey($record->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $enquiry) {
                        continue;
                    }

                    if ($enquiry->status === $target) {
                        continue;
                    }

                    $allowed = AdmissionEnquiry::BULK_TRANSITIONS[$enquiry->status] ?? [];

                    if (! in_array($target, $allowed, true)) {
                        $blocked++;

                        continue;
                    }

                    $old = $enquiry->only(['status']);
                    $enquiry->update(['status' => $target, 'updated_by' => $user->id]);
                    $this->audit->record('admission_enquiry.updated', $enquiry, $old, $enquiry->only(['status']));
                    $changed++;
                }
            });
        } catch (Throwable) {
            return BulkActionResult::failed('The enquiries could not be updated. No records were changed.');
        }

        if ($changed === 0 && $blocked > 0) {
            return BulkActionResult::failed(
                'None of the selected enquiries can move to that status from their current status. Nothing was changed.'
            );
        }

        $message = $changed === 1 ? '1 enquiry updated.' : $changed.' enquiries updated.';

        if ($blocked > 0) {
            $message .= ' '.$blocked.' '.($blocked === 1 ? 'was' : 'were').' skipped because that status change is not allowed from their current status.';
        }

        return BulkActionResult::success($message, $changed);
    }
}
