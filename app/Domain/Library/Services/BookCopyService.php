<?php

namespace App\Domain\Library\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Domain\Library\Support\CollegeRowLock;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\College;
use App\Models\LibraryTransaction;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BookCopyService — physical copies of an existing book master.
 *
 * Guarantees:
 *   - the copy and its book belong to the active college
 *   - accession number (and barcode, when present) are unique among the
 *     college's active copies
 *   - copy_number is unique among the book's active copies
 *   - status `issued` is never set from this service (circulation owns it)
 *   - a copy with transaction history, or one that is currently issued, cannot
 *     be deleted
 *
 * college_id / created_by / updated_by are stamped here, never copied from
 * the request.
 */
class BookCopyService
{
    private const DUPLICATE_ACCESSION = 'A copy with this accession number already exists for the active college.';

    private const DUPLICATE_BARCODE = 'A copy with this barcode already exists for the active college.';

    private const DUPLICATE_NUMBER = 'This book already has an active copy with that copy number.';

    private const IN_USE = 'This copy has circulation history and cannot be deleted.';

    private const ISSUED_LOCKED = 'This copy is issued. Return it or mark it lost before changing its status.';

    private const ISSUED_FORBIDDEN = 'A copy becomes issued only when it is lent to a member.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): BookCopy
    {
        return DB::transaction(function () use ($college, $data, $actor): BookCopy {
            CollegeRowLock::acquire((int) $college->getKey());

            $copy = new BookCopy([
                'college_id' => $college->getKey(),
                'book_id' => (int) $data['book_id'],
                'accession_number' => $data['accession_number'],
                'barcode' => $data['barcode'] ?? null,
                'copy_number' => (int) $data['copy_number'],
                'location' => $this->blankToNull($data['location'] ?? null),
                'condition' => $data['condition'],
                'status' => $data['status'],
                'acquired_on' => $this->dateOrNull($data['acquired_on'] ?? null),
                'remarks' => $this->blankToNull($data['remarks'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->assertBookBelongsToCollege($copy);
            $this->assertManualStatus($copy->status, null);
            $this->assertAcquiredOn($copy->acquired_on);
            $this->assertUniqueIdentifiers($copy);

            try {
                $copy->save();
            } catch (QueryException $e) {
                throw $this->duplicateFromQueryException($e);
            }

            $this->audit->record('book_copies.created', $copy, [], $this->snapshot($copy));

            return $copy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BookCopy $copy, array $data, User $actor): BookCopy
    {
        $this->assertTenant($copy);

        return DB::transaction(function () use ($copy, $data, $actor): BookCopy {
            CollegeRowLock::acquire((int) $copy->college_id);

            $locked = $this->lock($copy);
            $old = $this->snapshot($locked);

            if (array_key_exists('book_id', $data) && (int) $data['book_id'] !== (int) $locked->book_id) {
                throw ValidationException::withMessages([
                    'book_id' => 'The title of an existing copy cannot be changed. Record a new copy instead.',
                ]);
            }

            foreach (['accession_number', 'barcode', 'copy_number', 'location', 'condition', 'acquired_on', 'remarks'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $locked->{$field} = match ($field) {
                    'copy_number' => (int) $data[$field],
                    'location', 'remarks', 'barcode' => $this->blankToNull($data[$field]),
                    'acquired_on' => $this->dateOrNull($data[$field]),
                    default => $data[$field],
                };
            }

            if (array_key_exists('status', $data)) {
                $this->assertStatusTransition($locked, (string) $data['status']);
                $locked->status = $data['status'];
            }

            $locked->updated_by = $actor->getKey();

            $this->assertAcquiredOn($locked->acquired_on);
            $this->assertUniqueIdentifiers($locked);

            try {
                $locked->save();
            } catch (QueryException $e) {
                throw $this->duplicateFromQueryException($e);
            }

            $this->audit->record('book_copies.updated', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    public function delete(BookCopy $copy, User $actor): void
    {
        $this->assertTenant($copy);

        DB::transaction(function () use ($copy): void {
            CollegeRowLock::acquire((int) $copy->college_id);

            $locked = $this->lock($copy);

            if ($locked->isIssued() || $this->hasTransactionHistory($locked)) {
                throw ValidationException::withMessages(['copy' => self::IN_USE]);
            }

            $snapshot = $this->snapshot($locked);
            $locked->delete();

            $this->audit->record('book_copies.deleted', $locked, $snapshot, []);
        });
    }

    private function lock(BookCopy $copy): BookCopy
    {
        return BookCopy::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $copy->college_id)
            ->whereKey($copy->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertBookBelongsToCollege(BookCopy $copy): void
    {
        $ok = Book::withoutGlobalScope(CollegeScope::class)
            ->whereKey($copy->book_id)
            ->where('college_id', $copy->college_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages([
                'book_id' => 'The selected book does not belong to the active college.',
            ]);
        }
    }

    private function assertManualStatus(string $status, ?string $current): void
    {
        if ($status === BookCopy::STATUS_ISSUED && $current !== BookCopy::STATUS_ISSUED) {
            throw ValidationException::withMessages(['status' => self::ISSUED_FORBIDDEN]);
        }
    }

    private function assertStatusTransition(BookCopy $copy, string $incoming): void
    {
        if ($copy->isIssued() && $incoming !== BookCopy::STATUS_ISSUED) {
            throw ValidationException::withMessages(['status' => self::ISSUED_LOCKED]);
        }

        if ($incoming === BookCopy::STATUS_ISSUED && ! $copy->isIssued()) {
            throw ValidationException::withMessages(['status' => self::ISSUED_FORBIDDEN]);
        }

        if ($incoming !== BookCopy::STATUS_ISSUED && $this->hasOpenTransaction($copy)) {
            throw ValidationException::withMessages(['status' => self::ISSUED_LOCKED]);
        }
    }

    private function assertAcquiredOn(mixed $acquiredOn): void
    {
        if ($acquiredOn === null || $acquiredOn === '') {
            return;
        }

        $date = Carbon::parse($acquiredOn)->startOfDay();

        if ($date->gt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'acquired_on' => 'The acquisition date cannot be in the future.',
            ]);
        }
    }

    private function assertUniqueIdentifiers(BookCopy $copy): void
    {
        $base = BookCopy::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $copy->college_id)
            ->whereNull('deleted_at')
            ->when($copy->exists, fn ($query) => $query->whereKeyNot($copy->getKey()));

        if ((clone $base)->where('accession_number', $copy->accession_number)->exists()) {
            throw ValidationException::withMessages(['accession_number' => self::DUPLICATE_ACCESSION]);
        }

        if ($copy->barcode !== null && (clone $base)->where('barcode', $copy->barcode)->exists()) {
            throw ValidationException::withMessages(['barcode' => self::DUPLICATE_BARCODE]);
        }

        $numberTaken = BookCopy::withoutGlobalScope(CollegeScope::class)
            ->where('book_id', $copy->book_id)
            ->where('copy_number', $copy->copy_number)
            ->whereNull('deleted_at')
            ->when($copy->exists, fn ($query) => $query->whereKeyNot($copy->getKey()))
            ->exists();

        if ($numberTaken) {
            throw ValidationException::withMessages(['copy_number' => self::DUPLICATE_NUMBER]);
        }
    }

    private function hasTransactionHistory(BookCopy $copy): bool
    {
        return LibraryTransaction::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $copy->college_id)
            ->where('book_copy_id', $copy->getKey())
            ->exists();
    }

    private function hasOpenTransaction(BookCopy $copy): bool
    {
        return LibraryTransaction::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $copy->college_id)
            ->where('book_copy_id', $copy->getKey())
            ->where('status', LibraryTransaction::STATUS_ISSUED)
            ->exists();
    }

    private function duplicateFromQueryException(QueryException $e): ValidationException
    {
        $message = $e->getMessage();

        if (str_contains($message, 'accession_number')) {
            return ValidationException::withMessages(['accession_number' => self::DUPLICATE_ACCESSION]);
        }

        if (str_contains($message, 'barcode')) {
            return ValidationException::withMessages(['barcode' => self::DUPLICATE_BARCODE]);
        }

        if (str_contains($message, 'copy_number')) {
            return ValidationException::withMessages(['copy_number' => self::DUPLICATE_NUMBER]);
        }

        throw $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BookCopy $copy): array
    {
        return [
            'book_id' => (int) $copy->book_id,
            'accession_number' => $copy->accession_number,
            'barcode' => $copy->barcode,
            'copy_number' => (int) $copy->copy_number,
            'location' => $copy->location,
            'condition' => $copy->condition,
            'status' => $copy->status,
            'acquired_on' => $copy->acquired_on?->toDateString(),
            'remarks' => $copy->remarks,
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

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function assertTenant(BookCopy $copy): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $copy->college_id === (int) $collegeId, 403);
    }
}
