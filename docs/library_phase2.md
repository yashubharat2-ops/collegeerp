# Library Management — Phase 2 (Book Copies, Members, Issue / Return, Renewals)

Phase 1 catalogued titles. This phase records the physical copies, who may
borrow, and the circulation history. It does **not** add fines, reservations,
reports, barcode-scanner integrations, loan limits, or staff memberships.

Menu: **Library Management → Library Dashboard, Books, Book Categories,
Authors / Publishers, Book Copies, Library Members, Issue / Return, Renewals**
(exactly eight entries, each gated on its own `*.view` permission).

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_23_000006_create_book_copies_table`, `…000007_create_library_members_table`, `…000008_create_library_transactions_table`, `…000009_create_library_renewals_table` |
| Models | `BookCopy`, `LibraryMember`, `LibraryTransaction`, `LibraryRenewal`. `Book::copies()` is the only new book relation |
| Domain | `BookCopyService`, `LibraryMemberService`, `LibraryTransactionService`, `LibraryRenewalService`, `CollegeRowLock`. `LibraryFormOptions` gained the option lists these screens need |
| Policies | `BookCopyPolicy`, `LibraryMemberPolicy`, `LibraryTransactionPolicy`, `LibraryRenewalPolicy` |
| HTTP | Controllers and Form Requests under `BookCopy`, `LibraryMember`, `LibraryTransaction`, `LibraryRenewal`. Routes `book-copies`, `library-members` (full resource), `library-transactions` (no destroy; `return` and `lost` posts), `library-renewals` (index/create/store/show only) |
| Views | `resources/views/{book_copies,library_members,library_transactions,library_renewals}/` plus `library/status.blade.php` |
| RBAC | 14 new slugs, listed below. Idempotent `firstOrCreate` in `DatabaseSeeder`, granted to super-admin and college-admin |

`college_id`, `created_by`, `updated_by` and every actor column (`issued_by`,
`returned_by`, `renewed_by`) are stripped from requests and stamped on the
server. Super Admin still works through the active-college switch; nothing
here reads a browser-supplied college id.

## Data model

* **Book copy** — one physical item of a `books` row: `accession_number`
  (required), `barcode` (optional), `copy_number`, `location`, `condition`
  (`new|good|fair|poor`), `status` (`available|issued|lost|damaged|withdrawn`),
  `acquired_on`, `remarks`. Accession and barcode are unique among the
  college's non-deleted copies. Copy number is unique among a title's
  non-deleted copies. The title, ISBN and authors stay on the book.
* **Library member** — a membership of an existing `student_enrollments` row.
  It stores `member_code`, dates, status (`active|inactive|suspended|expired`)
  and remarks. It does not copy the student's name or number. Member code is
  unique per college. An enrollment may have only one active membership.
* **Issue** (`library_transactions`) — `book_copy_id`, `library_member_id`,
  `issued_on`, `due_on`, `returned_on`, `status` (`issued|returned|lost`),
  `issued_by`, `returned_by`, `remarks`. At most one `issued` row per copy.
  There is no delete.
* **Renewal** (`library_renewals`) — `issue_transaction_id`, `old_due_date`,
  `new_due_date`, `renewed_on`, `renewed_by`, `remarks`. Append-only. The
  transaction's `issued_on` is never rewritten; only `due_on` moves forward.

Partial unique indexes (SQLite and PostgreSQL) back the uniqueness rules,
including one open issue per copy. MySQL/MariaDB cannot express those
partial indexes; `CollegeRowLock` plus a re-check inside the transaction is
the equivalent guard, and a duplicate-key error is mapped back to a
validation message by column name.

## Rules

* A copy is created available, lost, damaged or withdrawn. `issued` is set
  only by issuing it, and cleared only by a return. A lost loan sets the copy
  to `lost`.
* Issue requires an available, non-deleted copy and an active member whose
  expiry is today or later, both in the active college. The copy and the
  member are row-locked. A second open issue of the same copy is rejected.
* Return sets `returned_on` / `returned_by`, keeps the issue row, and makes
  the copy available. The return date cannot precede the issue date or fall
  in the future. A lost issue cannot be returned.
* Renewal is allowed only while the issue is still `issued`. `new_due_date`
  must be strictly later than the current due date. `renewed_on` cannot
  precede the issue date or fall in the future. Each renewal is its own row.
* A copy or member with any transaction history cannot be deleted, nor can a
  copy that is currently issued. A book that still has non-deleted copies
  cannot be deleted. Transactions and renewals have no delete route.
* Soft-deleted accession numbers, barcodes, copy numbers and member codes can
  be reused. Another college may use the same identifiers.

## Permissions

`book_copies.view|create|update|delete`

`library_members.view|create|update|delete`

`library_transactions.view|create|update|return` — `return` covers both the
return and the mark-lost actions. There is no `library_transactions.delete`.

`library_renewals.view|create` — renewals are not edited or deleted.

Not seeded: fines, reports, reservations, `book_issues`, `book_returns`,
`book_renewals`.

## Limitations

No fines, overdue charges, reservations, hold queues, reports, barcode
hardware, maximum-loan counts, or memberships for staff. Overdue is only a
filter and a label on an open issue whose due date has passed.
