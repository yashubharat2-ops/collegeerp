<?php

namespace App\Domain\Library\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\LibraryFine;
use App\Models\LibraryMember;
use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use App\Models\Publisher;
use App\Domain\Library\Support\Isbn;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * LibraryReportService — the READ side of the Library Reports module.
 *
 * Eight live reports over the EXISTING Library Management records:
 *
 *   Books Report                 → books + book_categories + publishers + author_book
 *   Book Copies Report           → book_copies + their book
 *   Library Members Report       → library_members + the student enrollment they reference
 *   Issue / Return Report        → library_transactions + renewals
 *   Overdue Books Report         → library_transactions whose due date has passed
 *   Fine Report                  → library_fines (the stored fine rows)
 *   Lost / Damaged Books Report  → book_copies marked lost/damaged + their circulation
 *   Library Summary              → aggregates of all of the above
 *
 * There are no report tables, no snapshots and no second copy of any Library
 * fact: every row and every figure is read live from the operational records
 * through the models that already own them. Nothing in this class writes, and
 * no Library rule is re-implemented here — circulation statuses, member
 * eligibility, copy availability and fine amounts are the ones the operational
 * Library services stored (LibraryTransitionService, LibraryFineService,
 * LibraryMember, LibraryTransaction, LibraryFine).
 *
 * Rules honoured by every method:
 *
 *  - Tenant safety — every root query starts from a model carrying
 *    CollegeScope (BelongsToCollege), so a forged foreign filter id can only
 *    ever produce an empty report. Relationship filters travel through the
 *    existing relationships, never through a second query per row.
 *  - Deterministic pagination — 20 rows per page with a unique id tiebreak, so
 *    pages never overlap or skip rows, and filters survive pagination.
 *  - Constant query counts per page — counts and sums are SQL aggregates and
 *    relations are eager-loaded; nothing is fetched per row.
 *  - Respect existing rules — soft-deleted masters (books, copies, members)
 *    never appear; circulation history (library_transactions, library_fines)
 *    has no soft deletes and is therefore never hidden, exactly like the
 *    operational screens. `overdue` uses LibraryTransaction::isOverdue()'s
 *    definition (issued and past due) and the returned-late case is derived
 *    from the stored dates only.
 *  - Read-only — nothing in this class writes.
 *
 * Filter vocabulary of the service (the controller normalises the query string
 * into it): `status` is always the status of the row being listed (book status,
 * copy status, member status, transaction status, copy status for the lost /
 * damaged report), `availability` is the derived availability bucket of the
 * Book Copies Report, and `from` / `to` always mean the date window of the
 * selected report.
 */
class LibraryReportService
{
    /** Rows per page, matching the other REPORTS modules. */
    public const PER_PAGE = 20;

    /**
     * Derived availability buckets of the Book Copies Report, computed from the
     * existing copy status — no new column and no new state.
     */
    public const AVAILABILITY = ['available', 'on_loan', 'unavailable'];

    /** Copy statuses behind each availability bucket. */
    public const AVAILABILITY_STATUSES = [
        'available' => [BookCopy::STATUS_AVAILABLE],
        'on_loan' => [BookCopy::STATUS_ISSUED],
        'unavailable' => [BookCopy::STATUS_LOST, BookCopy::STATUS_DAMAGED, BookCopy::STATUS_WITHDRAWN],
    ];

    /** Statuses of the Lost / Damaged Books Report. */
    public const LOST_DAMAGED_STATUSES = [BookCopy::STATUS_LOST, BookCopy::STATUS_DAMAGED];

    /* ------------------------------------------------------------------ *\
     * 1. Books Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per catalogued title with its category, publisher, credited
     * authors and live copy counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function books(array $filters): array
    {
        $query = Book::query()
            ->when($filters['book_category_id'] ?? null, fn (Builder $q, $id) => $q->where('book_category_id', $id))
            ->when($filters['publisher_id'] ?? null, fn (Builder $q, $id) => $q->where('publisher_id', $id))
            ->when($filters['author_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('authors', fn (Builder $author) => $author->whereKey($id)))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%")
                    ->orWhere('edition', 'like', "%{$search}%")
                    ->orWhere('language', 'like', "%{$search}%");

                // ISBNs are stored in the canonical form Isbn::normalize()
                // produces, so a hyphenated search ("978-0-262") matches the
                // stored digits as well.
                $normalized = Isbn::normalize($search);

                if ($normalized !== null && $normalized !== $search) {
                    $inner->orWhere('isbn', 'like', "%{$normalized}%");
                }
            }));

        $rows = (clone $query)
            ->with(['category:id,name,code', 'publisher:id,name', 'authors:id,name'])
            ->withCount([
                'copies',
                'copies as available_copies_count' => fn (Builder $copies) => $copies->where('status', BookCopy::STATUS_AVAILABLE),
                'copies as issued_copies_count' => fn (Builder $copies) => $copies->where('status', BookCopy::STATUS_ISSUED),
            ])
            ->orderBy('title')
            ->orderBy('books.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'books' => (int) (clone $query)->count(),
                'active' => (int) (clone $query)->where('status', Book::STATUS_ACTIVE)->count(),
                'inactive' => (int) (clone $query)->where('status', Book::STATUS_INACTIVE)->count(),
                'without_copies' => (int) (clone $query)->whereDoesntHave('copies')->count(),
                'copies' => (int) BookCopy::query()->whereIn('book_id', (clone $query)->select('books.id'))->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 2. Book Copies Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per physical copy with the title it belongs to and its derived
     * availability bucket.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function copies(array $filters): array
    {
        $query = BookCopy::query()
            ->when($filters['book_id'] ?? null, fn (Builder $q, $id) => $q->where('book_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['availability'] ?? null, fn (Builder $q, $bucket) => $q->whereIn('status', self::AVAILABILITY_STATUSES[$bucket] ?? []))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('accession_number', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('book', fn (Builder $book) => $book
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            }));

        $rows = (clone $query)
            ->with(['book:id,title,code,book_category_id', 'book.category:id,name,code'])
            ->orderBy('accession_number')
            ->orderBy('book_copies.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'copies' => (int) (clone $query)->count(),
                'available' => (int) (clone $query)->where('status', BookCopy::STATUS_AVAILABLE)->count(),
                'on_loan' => (int) (clone $query)->where('status', BookCopy::STATUS_ISSUED)->count(),
                'lost' => (int) (clone $query)->where('status', BookCopy::STATUS_LOST)->count(),
                'damaged' => (int) (clone $query)->where('status', BookCopy::STATUS_DAMAGED)->count(),
                'withdrawn' => (int) (clone $query)->where('status', BookCopy::STATUS_WITHDRAWN)->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 3. Library Members Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per membership with the student enrollment it references (the
     * Library module keeps no second identity) and the live loan counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function members(array $filters): array
    {
        $query = LibraryMember::query()
            ->when($filters['student_enrollment_id'] ?? null, fn (Builder $q, $id) => $q->where('student_enrollment_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('member_code', 'like', "%{$search}%")
                    ->orWhereHas('studentEnrollment', fn (Builder $enrollment) => $enrollment
                        ->where('enrollment_number', 'like', "%{$search}%")
                        ->orWhereHas('student', fn (Builder $student) => $student
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('student_number', 'like', "%{$search}%")));
            }));

        $rows = (clone $query)
            ->with([
                'studentEnrollment:id,student_id,academic_year_id,enrollment_number',
                'studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'studentEnrollment.academicYear:id,name',
                'studentEnrollment.program:id,name,code',
            ])
            ->withCount([
                'transactions',
                'transactions as open_issues_count' => fn (Builder $issues) => $issues->where('status', LibraryTransaction::STATUS_ISSUED),
                'transactions as overdue_issues_count' => fn (Builder $issues) => $issues
                    ->where('status', LibraryTransaction::STATUS_ISSUED)
                    ->whereDate('due_on', '<', now()->toDateString()),
            ])
            ->orderBy('member_code')
            ->orderBy('library_members.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'members' => (int) (clone $query)->count(),
                'active' => (int) (clone $query)->where('status', LibraryMember::STATUS_ACTIVE)->count(),
                'inactive' => (int) (clone $query)->where('status', LibraryMember::STATUS_INACTIVE)->count(),
                'suspended' => (int) (clone $query)->where('status', LibraryMember::STATUS_SUSPENDED)->count(),
                'expired' => (int) (clone $query)->where('status', LibraryMember::STATUS_EXPIRED)->count(),
                'past_expiry' => (int) (clone $query)->whereDate('expiry_date', '<', now()->toDateString())->count(),
                'with_open_issues' => (int) (clone $query)->whereHas('transactions', fn (Builder $issues) => $issues
                    ->where('status', LibraryTransaction::STATUS_ISSUED))->count(),
                'open_issues' => (int) LibraryTransaction::query()
                    ->whereIn('library_member_id', (clone $query)->select('library_members.id'))
                    ->where('status', LibraryTransaction::STATUS_ISSUED)
                    ->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 4. Issue / Return Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per circulation record (issue, return or lost) with its copy,
     * title, member and renewal count.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function issues(array $filters): array
    {
        $query = $this->transactionQuery($filters, 'issued_on');

        $rows = (clone $query)
            ->with($this->transactionRelations())
            ->withCount('renewals')
            ->orderByDesc('issued_on')
            ->orderByDesc('library_transactions.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $today = now()->toDateString();

        return [
            'rows' => $rows,
            'totals' => [
                'issues' => (int) (clone $query)->count(),
                'issued' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_ISSUED)->count(),
                'returned' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_RETURNED)->count(),
                'lost' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_LOST)->count(),
                'open_overdue' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_ISSUED)
                    ->whereDate('due_on', '<', $today)->count(),
                'returned_late' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_RETURNED)
                    ->whereColumn('returned_on', '>', 'due_on')->count(),
                'renewals' => (int) LibraryRenewal::query()
                    ->whereIn('issue_transaction_id', (clone $query)->select('library_transactions.id'))
                    ->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 5. Overdue Books Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per circulation record that is (or has been) overdue: a copy that
     * is still out past its due date, a copy returned after its due date, or a
     * copy lost after its due date. The definition matches
     * LibraryTransaction::isOverdue() for open loans; the other two cases are
     * read from the stored dates and never recalculate a fine.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function overdue(array $filters): array
    {
        $today = now()->toDateString();

        $query = $this->transactionQuery($filters, 'due_on')
            ->where(function (Builder $overdue) use ($today): void {
                $overdue->where(fn (Builder $open) => $open
                    ->where('status', LibraryTransaction::STATUS_ISSUED)
                    ->whereDate('due_on', '<', $today))
                    ->orWhere(fn (Builder $late) => $late
                        ->where('status', LibraryTransaction::STATUS_RETURNED)
                        ->whereColumn('returned_on', '>', 'due_on'))
                    ->orWhere(fn (Builder $lost) => $lost
                        ->where('status', LibraryTransaction::STATUS_LOST)
                        ->whereDate('due_on', '<', $today));
            });

        $rows = (clone $query)
            ->with($this->transactionRelations())
            ->withCount('renewals')
            ->orderBy('due_on')
            ->orderBy('library_transactions.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'overdue' => (int) (clone $query)->count(),
                'still_issued' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_ISSUED)->count(),
                'returned_late' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_RETURNED)->count(),
                'lost' => (int) (clone $query)->where('status', LibraryTransaction::STATUS_LOST)->count(),
            ],
        ];
    }

    /**
     * Days a circulation record is (or was) past its due date. Displayed with
     * the row — the enforced overdue figure stays with LibraryFineService.
     */
    public static function daysOverdue(LibraryTransaction $transaction): int
    {
        if ($transaction->due_on === null) {
            return 0;
        }

        $end = $transaction->returned_on ?? now();

        $days = CarbonImmutable::parse($transaction->due_on)->startOfDay()
            ->diffInDays(CarbonImmutable::parse($end)->startOfDay(), false);

        return max(0, (int) $days);
    }

    /* ------------------------------------------------------------------ *\
     * 6. Fine Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per stored fine with the member, the book behind the transaction
     * and the outstanding amount calculated by LibraryFine::outstanding(). The
     * report never computes a fine: amount, days and rate come from the row the
     * LibraryFineService wrote.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>, by_status: \Illuminate\Support\Collection<int, object>}
     */
    public function fines(array $filters): array
    {
        $query = LibraryFine::query()
            ->when($filters['library_member_id'] ?? null, fn (Builder $q, $id) => $q->where('library_member_id', $id))
            ->when($filters['book_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('transaction.bookCopy', fn (Builder $copy) => $copy->where('book_id', $id)))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('period_end', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('period_start', '<=', $to))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('payment_reference', 'like', "%{$search}%")
                    ->orWhereHas('member', fn (Builder $member) => $member->where('member_code', 'like', "%{$search}%"))
                    ->orWhereHas('transaction.bookCopy.book', fn (Builder $book) => $book
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            }));

        $rows = (clone $query)
            ->with([
                'member:id,member_code,student_enrollment_id',
                'member.studentEnrollment:id,student_id,enrollment_number',
                'member.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                'transaction:id,book_copy_id,library_member_id,issued_on,due_on,returned_on,status',
                'transaction.bookCopy:id,book_id,accession_number',
                'transaction.bookCopy.book:id,title,code',
            ])
            ->orderByDesc('period_start')
            ->orderByDesc('library_fines.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One grouped query feeds the headline figures and the per-status table.
        $byStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(assessed_amount), 0) as assessed, COALESCE(SUM(paid_amount), 0) as paid')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $totals = [
            'fines' => 0,
            'pending' => 0,
            'assessed' => 0,
            'paid' => 0,
            'waived' => 0,
            'assessed_amount' => 0.0,
            'paid_amount' => 0.0,
        ];

        foreach ($byStatus as $group) {
            $count = (int) $group->total;
            $totals['fines'] += $count;
            $totals[$group->status] = $count;
            $totals['assessed_amount'] += (float) $group->assessed;
            $totals['paid_amount'] += (float) $group->paid;
        }

        // Outstanding mirrors LibraryFine::outstanding() (assessed − paid).
        $totals['outstanding'] = round($totals['assessed_amount'] - $totals['paid_amount'], 2);
        foreach (['pending', 'assessed', 'paid', 'waived', 'assessed_amount', 'paid_amount'] as $key) {
            $totals[$key] ??= 0;
        }

        return ['rows' => $rows, 'totals' => $totals, 'by_status' => $byStatus];
    }

    /* ------------------------------------------------------------------ *\
     * 7. Lost / Damaged Books Report
     * \* ------------------------------------------------------------------ */

    /**
     * One row per copy currently marked lost or damaged, with its title and the
     * last circulation record that involved it (member and dates), read from the
     * existing transaction rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>}
     */
    public function lostDamaged(array $filters): array
    {
        $query = BookCopy::query()
            ->whereIn('status', self::LOST_DAMAGED_STATUSES)
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['book_id'] ?? null, fn (Builder $q, $id) => $q->where('book_id', $id))
            ->when($filters['book_copy_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['library_member_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('transactions', fn (Builder $issues) => $issues->where('library_member_id', $id)))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('acquired_on', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('acquired_on', '<=', $to))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('accession_number', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('book', fn (Builder $book) => $book
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            }));

        $rows = (clone $query)
            ->with([
                'book:id,title,code,book_category_id',
                'book.category:id,name,code',
                'transactions' => fn ($issues) => $issues
                    ->with([
                        'libraryMember:id,member_code,student_enrollment_id',
                        'libraryMember.studentEnrollment:id,student_id,enrollment_number',
                        'libraryMember.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
                    ])
                    ->orderByDesc('issued_on')
                    ->orderByDesc('library_transactions.id'),
            ])
            ->orderBy('accession_number')
            ->orderBy('book_copies.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'rows' => $rows,
            'totals' => [
                'copies' => (int) (clone $query)->count(),
                'lost' => (int) (clone $query)->where('status', BookCopy::STATUS_LOST)->count(),
                'damaged' => (int) (clone $query)->where('status', BookCopy::STATUS_DAMAGED)->count(),
                'titles' => (int) (clone $query)->distinct()->count('book_id'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * 8. Library Summary
     * \* ------------------------------------------------------------------ */

    /**
     * The live Library position of the active college. Every figure is the same
     * source the operational screens use; there is no report table and no
     * cached total.
     *
     * @param  array<string, mixed>  $filters  (the summary has no filters)
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $today = now()->toDateString();

        $fineStatuses = LibraryFine::query()
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(assessed_amount), 0) as assessed, COALESCE(SUM(paid_amount), 0) as paid')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $fines = [
            'fines' => (int) $fineStatuses->sum('total'),
            'pending' => (int) ($fineStatuses->get(LibraryFine::STATUS_PENDING)?->total ?? 0),
            'assessed' => (int) ($fineStatuses->get(LibraryFine::STATUS_ASSESSED)?->total ?? 0),
            'paid' => (int) ($fineStatuses->get(LibraryFine::STATUS_PAID)?->total ?? 0),
            'waived' => (int) ($fineStatuses->get(LibraryFine::STATUS_WAIVED)?->total ?? 0),
            'assessed_amount' => round((float) $fineStatuses->sum('assessed'), 2),
            'paid_amount' => round((float) $fineStatuses->sum('paid'), 2),
        ];
        $fines['outstanding'] = round($fines['assessed_amount'] - $fines['paid_amount'], 2);
        $fines['by_status'] = $fineStatuses->values()->all();

        return [
            'books' => [
                'total' => Book::query()->count(),
                'active' => Book::query()->where('status', Book::STATUS_ACTIVE)->count(),
                'inactive' => Book::query()->where('status', Book::STATUS_INACTIVE)->count(),
            ],
            'copies' => [
                'total' => BookCopy::query()->count(),
                'available' => BookCopy::query()->where('status', BookCopy::STATUS_AVAILABLE)->count(),
                'issued' => BookCopy::query()->where('status', BookCopy::STATUS_ISSUED)->count(),
                'lost' => BookCopy::query()->where('status', BookCopy::STATUS_LOST)->count(),
                'damaged' => BookCopy::query()->where('status', BookCopy::STATUS_DAMAGED)->count(),
                'withdrawn' => BookCopy::query()->where('status', BookCopy::STATUS_WITHDRAWN)->count(),
            ],
            'members' => [
                'total' => LibraryMember::query()->count(),
                'active' => LibraryMember::query()->where('status', LibraryMember::STATUS_ACTIVE)->count(),
                'inactive' => LibraryMember::query()->where('status', LibraryMember::STATUS_INACTIVE)->count(),
                'suspended' => LibraryMember::query()->where('status', LibraryMember::STATUS_SUSPENDED)->count(),
                'expired' => LibraryMember::query()->where('status', LibraryMember::STATUS_EXPIRED)->count(),
                'past_expiry' => LibraryMember::query()->whereDate('expiry_date', '<', $today)->count(),
            ],
            'circulation' => [
                'issues' => LibraryTransaction::query()->count(),
                'issued' => LibraryTransaction::query()->where('status', LibraryTransaction::STATUS_ISSUED)->count(),
                'returned' => LibraryTransaction::query()->where('status', LibraryTransaction::STATUS_RETURNED)->count(),
                'lost' => LibraryTransaction::query()->where('status', LibraryTransaction::STATUS_LOST)->count(),
                'overdue' => LibraryTransaction::query()
                    ->where('status', LibraryTransaction::STATUS_ISSUED)
                    ->whereDate('due_on', '<', $today)
                    ->count(),
                'renewals' => LibraryRenewal::query()->count(),
            ],
            'fines' => $fines,
            'lost_damaged' => [
                'copies' => BookCopy::query()->whereIn('status', self::LOST_DAMAGED_STATUSES)->count(),
                'lost' => BookCopy::query()->where('status', BookCopy::STATUS_LOST)->count(),
                'damaged' => BookCopy::query()->where('status', BookCopy::STATUS_DAMAGED)->count(),
                'titles' => BookCopy::query()->whereIn('status', self::LOST_DAMAGED_STATUSES)->distinct()->count('book_id'),
            ],
            'master' => [
                'categories' => BookCategory::query()->count(),
                'authors' => Author::query()->count(),
                'publishers' => Publisher::query()->count(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *\
     * Shared query building
     * \* ------------------------------------------------------------------ */

    /**
     * The circulation report root query: tenant-scoped LibraryTransaction rows
     * narrowed by the filters every circulation report shares. `$dateColumn` is
     * the stored date the from/to window applies to (issued_on for the Issue /
     * Return Report, due_on for the Overdue Books Report).
     *
     * @param  array<string, mixed>  $filters
     */
    private function transactionQuery(array $filters, string $dateColumn): Builder
    {
        return LibraryTransaction::query()
            ->when($filters['book_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('bookCopy', fn (Builder $copy) => $copy->where('book_id', $id)))
            ->when($filters['library_member_id'] ?? null, fn (Builder $q, $id) => $q->where('library_member_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate($dateColumn, '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate($dateColumn, '<=', $to))
            ->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(function (Builder $inner) use ($search): void {
                $inner->whereHas('bookCopy', fn (Builder $copy) => $copy
                    ->where('accession_number', 'like', "%{$search}%")
                    ->orWhereHas('book', fn (Builder $book) => $book
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")));

                $inner->orWhereHas('libraryMember', fn (Builder $member) => $member
                    ->where('member_code', 'like', "%{$search}%")
                    ->orWhereHas('studentEnrollment', fn (Builder $enrollment) => $enrollment
                        ->where('enrollment_number', 'like', "%{$search}%")
                        ->orWhereHas('student', fn (Builder $student) => $student
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('student_number', 'like', "%{$search}%"))));
            }));
    }

    /**
     * Relations every circulation row renders (eager loaded, so a page costs a
     * fixed number of queries).
     *
     * @return array<int, string|callable>
     */
    private function transactionRelations(): array
    {
        return [
            'bookCopy:id,book_id,accession_number,barcode,status',
            'bookCopy.book:id,title,code,book_category_id',
            'bookCopy.book.category:id,name,code',
            'libraryMember:id,member_code,student_enrollment_id',
            'libraryMember.studentEnrollment:id,student_id,enrollment_number',
            'libraryMember.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
        ];
    }
}
