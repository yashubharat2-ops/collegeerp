<?php

namespace App\Domain\Library\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Domain\Library\Support\CollegeRowLock;
use App\Models\BookCopy;
use App\Models\College;
use App\Models\LibraryMember;
use App\Models\LibraryTransaction;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LibraryTransactionService — issue, return and mark-lost.
 *
 * Every mutation runs in a transaction that locks the college row and the
 * copy (and, on issue, the member). Availability is re-checked after the lock,
 * so two requests cannot issue the same copy. A partial unique index on open
 * issues is the database backstop where the engine supports it.
 *
 * issued_on and issued_by are written once. Return sets returned_on /
 * returned_by and makes the copy available again. Lost closes the loan and
 * marks the copy lost. Neither path deletes the row.
 */
class LibraryTransactionService
{
    private const COPY_UNAVAILABLE = 'This copy is not available to issue.';

    private const COPY_ALREADY_ISSUED = 'This copy is already issued.';

    private const COPY_FOREIGN = 'The selected copy does not belong to the active college.';

    private const MEMBER_FOREIGN = 'The selected member does not belong to the active college.';

    private const MEMBER_INACTIVE = 'Only an active library member with a current membership can borrow.';

    private const NOT_ISSUED = 'Only an issued copy can be returned or marked lost.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(College $college, array $data, User $actor): LibraryTransaction
    {
        return DB::transaction(function () use ($college, $data, $actor): LibraryTransaction {
            $collegeId = (int) $college->getKey();
            CollegeRowLock::acquire($collegeId);

            $copy = BookCopy::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $collegeId)
                ->whereKey((int) $data['book_copy_id'])
                ->lockForUpdate()
                ->first();

            if (! $copy || $copy->trashed()) {
                throw ValidationException::withMessages(['book_copy_id' => self::COPY_FOREIGN]);
            }

            $member = LibraryMember::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $collegeId)
                ->whereKey((int) $data['library_member_id'])
                ->lockForUpdate()
                ->first();

            if (! $member || $member->trashed()) {
                throw ValidationException::withMessages(['library_member_id' => self::MEMBER_FOREIGN]);
            }

            $this->assertMemberCanBorrow($member);

            if (! $copy->isAvailable()) {
                throw ValidationException::withMessages([
                    'book_copy_id' => $copy->isIssued() ? self::COPY_ALREADY_ISSUED : self::COPY_UNAVAILABLE,
                ]);
            }

            if ($this->openIssueExists($copy)) {
                throw ValidationException::withMessages(['book_copy_id' => self::COPY_ALREADY_ISSUED]);
            }

            $issuedOn = Carbon::parse($data['issued_on'])->startOfDay();
            $dueOn = Carbon::parse($data['due_on'])->startOfDay();
            $this->assertIssueDates($issuedOn, $dueOn);

            $transaction = new LibraryTransaction([
                'college_id' => $collegeId,
                'book_copy_id' => $copy->getKey(),
                'library_member_id' => $member->getKey(),
                'issued_on' => $issuedOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'returned_on' => null,
                'status' => LibraryTransaction::STATUS_ISSUED,
                'issued_by' => $actor->getKey(),
                'returned_by' => null,
                'remarks' => $this->blankToNull($data['remarks'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            try {
                $transaction->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'book_copy_id')) {
                    throw ValidationException::withMessages(['book_copy_id' => self::COPY_ALREADY_ISSUED]);
                }

                throw $e;
            }

            $copy->status = BookCopy::STATUS_ISSUED;
            $copy->updated_by = $actor->getKey();
            $copy->save();

            $this->audit->record('library_transactions.issued', $transaction, [], $this->snapshot($transaction));

            return $transaction->refresh();
        });
    }

    /**
     * Remarks only. Dates, parties and status are circulation actions, not edits.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryTransaction $transaction, array $data, User $actor): LibraryTransaction
    {
        $this->assertTenant($transaction);

        return DB::transaction(function () use ($transaction, $data, $actor): LibraryTransaction {
            CollegeRowLock::acquire((int) $transaction->college_id);

            $locked = $this->lock($transaction);
            $old = $this->snapshot($locked);

            if (array_key_exists('remarks', $data)) {
                $locked->remarks = $this->blankToNull($data['remarks']);
            }

            $locked->updated_by = $actor->getKey();
            $locked->save();

            $this->audit->record('library_transactions.updated', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function returnCopy(LibraryTransaction $transaction, array $data, User $actor): LibraryTransaction
    {
        $this->assertTenant($transaction);

        return DB::transaction(function () use ($transaction, $data, $actor): LibraryTransaction {
            CollegeRowLock::acquire((int) $transaction->college_id);

            $locked = $this->lock($transaction);
            $old = $this->snapshot($locked);

            if (! $locked->isIssued()) {
                throw ValidationException::withMessages(['status' => 'Only an issued copy can be returned.']);
            }

            $returnedOn = Carbon::parse($data['returned_on'])->startOfDay();
            $issuedOn = Carbon::parse($locked->issued_on)->startOfDay();

            if ($returnedOn->lt($issuedOn)) {
                throw ValidationException::withMessages([
                    'returned_on' => 'The return date cannot be before the issue date.',
                ]);
            }

            if ($returnedOn->gt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'returned_on' => 'The return date cannot be in the future.',
                ]);
            }

            $note = $this->blankToNull($data['remarks'] ?? null);
            $locked->status = LibraryTransaction::STATUS_RETURNED;
            $locked->returned_on = $returnedOn->toDateString();
            $locked->returned_by = $actor->getKey();
            $locked->updated_by = $actor->getKey();
            $locked->remarks = $this->appendNote($locked->remarks, 'Return note', $note);
            $locked->save();

            $copy = $this->lockCopy($locked);
            $copy->status = BookCopy::STATUS_AVAILABLE;
            $copy->updated_by = $actor->getKey();
            $copy->save();

            $this->audit->record('library_transactions.returned', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function markLost(LibraryTransaction $transaction, array $data, User $actor): LibraryTransaction
    {
        $this->assertTenant($transaction);

        return DB::transaction(function () use ($transaction, $data, $actor): LibraryTransaction {
            CollegeRowLock::acquire((int) $transaction->college_id);

            $locked = $this->lock($transaction);
            $old = $this->snapshot($locked);

            if (! $locked->isIssued()) {
                throw ValidationException::withMessages(['status' => self::NOT_ISSUED]);
            }

            $note = $this->blankToNull($data['remarks'] ?? null);
            $locked->status = LibraryTransaction::STATUS_LOST;
            $locked->returned_on = null;
            $locked->returned_by = null;
            $locked->updated_by = $actor->getKey();
            $locked->remarks = $this->appendNote($locked->remarks, 'Lost note', $note);
            $locked->save();

            $copy = $this->lockCopy($locked);
            $copy->status = BookCopy::STATUS_LOST;
            $copy->updated_by = $actor->getKey();
            $copy->save();

            $this->audit->record('library_transactions.lost', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    private function assertMemberCanBorrow(LibraryMember $member): void
    {
        $enrollmentOk = StudentEnrollment::withoutGlobalScope(CollegeScope::class)
            ->whereKey($member->student_enrollment_id)
            ->where('college_id', $member->college_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $enrollmentOk) {
            throw ValidationException::withMessages([
                'library_member_id' => 'The member\'s enrollment is no longer on record for the active college.',
            ]);
        }

        if (! $member->canBorrow()) {
            throw ValidationException::withMessages(['library_member_id' => self::MEMBER_INACTIVE]);
        }
    }

    private function assertIssueDates(Carbon $issuedOn, Carbon $dueOn): void
    {
        if ($issuedOn->gt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'issued_on' => 'The issue date cannot be in the future.',
            ]);
        }

        if (! $dueOn->gt($issuedOn)) {
            throw ValidationException::withMessages([
                'due_on' => 'The due date must be later than the issue date.',
            ]);
        }
    }

    private function openIssueExists(BookCopy $copy): bool
    {
        return LibraryTransaction::withoutGlobalScope(CollegeScope::class)
            ->where('book_copy_id', $copy->getKey())
            ->where('status', LibraryTransaction::STATUS_ISSUED)
            ->exists();
    }

    private function lock(LibraryTransaction $transaction): LibraryTransaction
    {
        return LibraryTransaction::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $transaction->college_id)
            ->whereKey($transaction->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCopy(LibraryTransaction $transaction): BookCopy
    {
        return BookCopy::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $transaction->college_id)
            ->whereKey($transaction->book_copy_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function appendNote(?string $existing, string $label, ?string $note): ?string
    {
        if ($note === null) {
            return $existing;
        }

        $existing = trim((string) $existing);
        $line = $label.': '.$note;

        return $existing === '' ? $line : $existing."\n\n".$line;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(LibraryTransaction $transaction): array
    {
        return [
            'book_copy_id' => (int) $transaction->book_copy_id,
            'library_member_id' => (int) $transaction->library_member_id,
            'issued_on' => $transaction->issued_on?->toDateString(),
            'due_on' => $transaction->due_on?->toDateString(),
            'returned_on' => $transaction->returned_on?->toDateString(),
            'status' => $transaction->status,
            'issued_by' => $transaction->issued_by,
            'returned_by' => $transaction->returned_by,
            'remarks' => $transaction->remarks,
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(LibraryTransaction $transaction): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $transaction->college_id === (int) $collegeId, 403);
    }
}
