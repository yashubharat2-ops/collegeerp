<?php

namespace App\Domain\Library\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Domain\Library\Support\CollegeRowLock;
use App\Models\BookCopy;
use App\Models\College;
use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LibraryRenewalService — extend an active issue without rewriting it.
 *
 * The renewal row stores old_due_date and new_due_date. The transaction's
 * issued_on, issued_by, copy and member are left untouched; only due_on moves
 * forward. A returned or lost issue cannot be renewed. The copy and the
 * transaction are locked so a return cannot land in the middle of a renewal.
 */
class LibraryRenewalService
{
    private const NOT_OPEN = 'Only an active issue can be renewed.';

    private const NOT_LATER = 'The new due date must be later than the current due date.';

    private const FOREIGN = 'The selected issue does not belong to the active college.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): LibraryRenewal
    {
        return DB::transaction(function () use ($college, $data, $actor): LibraryRenewal {
            $collegeId = (int) $college->getKey();
            CollegeRowLock::acquire($collegeId);

            $transaction = LibraryTransaction::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $collegeId)
                ->whereKey((int) $data['issue_transaction_id'])
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw ValidationException::withMessages(['issue_transaction_id' => self::FOREIGN]);
            }

            if (! $transaction->isIssued()) {
                throw ValidationException::withMessages(['issue_transaction_id' => self::NOT_OPEN]);
            }

            // Lock the copy too, so a concurrent return cannot close the loan
            // after we have decided it is still issued.
            BookCopy::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $collegeId)
                ->whereKey($transaction->book_copy_id)
                ->lockForUpdate()
                ->first();

            $transaction->refresh();

            if (! $transaction->isIssued()) {
                throw ValidationException::withMessages(['issue_transaction_id' => self::NOT_OPEN]);
            }

            $renewedOn = Carbon::parse($data['renewed_on'] ?? now())->startOfDay();
            $newDue = Carbon::parse($data['new_due_date'])->startOfDay();
            $currentDue = Carbon::parse($transaction->due_on)->startOfDay();
            $issuedOn = Carbon::parse($transaction->issued_on)->startOfDay();

            if ($renewedOn->gt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'renewed_on' => 'The renewal date cannot be in the future.',
                ]);
            }

            if ($renewedOn->lt($issuedOn)) {
                throw ValidationException::withMessages([
                    'renewed_on' => 'The renewal date cannot be before the issue date.',
                ]);
            }

            if (! $newDue->gt($currentDue)) {
                throw ValidationException::withMessages(['new_due_date' => self::NOT_LATER]);
            }

            $oldDue = $currentDue->toDateString();
            $oldSnapshot = [
                'due_on' => $oldDue,
                'status' => $transaction->status,
                'issued_on' => $transaction->issued_on?->toDateString(),
            ];

            $renewal = LibraryRenewal::create([
                'college_id' => $collegeId,
                'issue_transaction_id' => $transaction->getKey(),
                'old_due_date' => $oldDue,
                'new_due_date' => $newDue->toDateString(),
                'renewed_on' => $renewedOn->toDateString(),
                'renewed_by' => $actor->getKey(),
                'remarks' => $this->blankToNull($data['remarks'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $transaction->due_on = $newDue->toDateString();
            $transaction->updated_by = $actor->getKey();
            $transaction->save();

            $this->audit->record('library_renewals.created', $renewal, [], [
                'issue_transaction_id' => (int) $renewal->issue_transaction_id,
                'old_due_date' => $renewal->old_due_date?->toDateString(),
                'new_due_date' => $renewal->new_due_date?->toDateString(),
                'renewed_on' => $renewal->renewed_on?->toDateString(),
                'renewed_by' => $renewal->renewed_by,
                'remarks' => $renewal->remarks,
            ]);

            $this->audit->record('library_transactions.renewed', $transaction, $oldSnapshot, [
                'due_on' => $transaction->due_on?->toDateString(),
                'status' => $transaction->status,
                'issued_on' => $transaction->issued_on?->toDateString(),
                'renewal_id' => $renewal->getKey(),
            ]);

            return $renewal->refresh();
        });
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
