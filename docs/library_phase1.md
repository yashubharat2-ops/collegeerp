# Library Management — Phase 1 (Books, Categories, Authors / Publishers)

**Scope of this phase:** the library's *bibliographic masters* only —
`book_categories`, `authors`, `publishers`, `books` and the `author_book`
link — plus a read-only **Library Dashboard** over them. Book Copies, Library
Members, Issue / Return, Renewals, Fines and Library Reports are deliberately
**not** implemented; their tables are not created here and nothing in this
phase can lend, return or charge anything.

Menu: **Library Management → Library Dashboard, Books, Book Categories,
Authors / Publishers** (exactly four entries).

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_23_000001_create_book_categories_table`, `…000002_create_authors_table`, `…000003_create_publishers_table`, `…000004_create_books_table`, `…000005_create_author_book_table` |
| Models | `App\Models\BookCategory`, `App\Models\Author`, `App\Models\Publisher`, `App\Models\Book`; marker `App\Models\LibraryDashboard` (not Eloquent — the dashboard has no table) |
| Domain | `App\Domain\Library\Services\{BookCategoryService, AuthorService, PublisherService, BookService, LibraryDashboardService}`, `App\Domain\Library\Support\{Isbn, LibraryFormOptions}`, `App\Domain\Library\Rules\ValidIsbn` |
| Policies | `BookCategoryPolicy`, `AuthorPolicy`, `PublisherPolicy`, `BookPolicy`, `LibraryDashboardPolicy` (registered in `AuthServiceProvider`) |
| HTTP | `BookCategoryController`, `AuthorController`, `PublisherController`, `BookController`, `LibraryDashboardController`; Form Requests under `App\Http\Requests\{BookCategory,Author,Publisher,Book}`; routes `book-categories`, `authors`, `publishers` (resource, no `show`), `books` (full resource incl. `show`), `library/dashboard` (`library.dashboard`) |
| Views | `resources/views/{book_categories,authors,publishers,books,library_dashboard}/…` (shared `authors/_tabs.blade.php` switches between Authors and Publishers) |
| Navigation | new **Library Management** sidebar section with exactly four permission-gated entries |
| RBAC | `library_dashboard.view`; `books.*`, `book_categories.*`, `authors.*`, `publishers.*` (`view/create/update/delete`) — 17 slugs, centralized in `DatabaseSeeder`, idempotent, granted to super-admin and college-admin |
| Tests | `tests/Feature/Library/{BookCategoryManagementTest, AuthorPublisherManagementTest, BookManagementTest, LibraryDashboardTest, LibraryNavigationTest, LibraryModuleSeederTest, LibraryModelRelationshipTest}`, `tests/Unit/Library/IsbnTest` |

No Platform master is duplicated: every row references the existing
`colleges.id`, and `created_by` / `updated_by` reference `users.id`.

## Data model

* **Book** = a *title*, not a physical item: `title`, `code` (the library's own
  catalogue code, required), `isbn` (optional), `book_category_id` (required),
  `publisher_id` (optional, `nullOnDelete`), `edition`, `publication_year`,
  `language`, `description`, `status`. Copies (accession numbers, shelf,
  condition, availability) are a Phase 2 table that will reference `books.id`;
  the book master therefore has no quantity / availability columns.
* **Book ↔ Author** is many-to-many through `author_book` (`college_id`,
  `book_id`, `author_id`, `sort_order`, unique per `(book_id, author_id)`), so a
  title can credit several authors in title-page order and an author can be
  credited on many titles.
* **Author** and **Publisher** are reusable per-college references. Each keeps
  a model-maintained `name_normalized` (lower-cased, whitespace-collapsed copy
  of `name`) that duplicate detection compares on, so "J. K. Rowling" and
  "j. k.  rowling" are the same author on every database engine. Publishers
  also carry optional `email`, `phone`, `website`, `address`.
* **Book Category**: `name`, `code`, `description`, `status`.
* Every table: `college_id` (cascade), `status` (`active` / `inactive`),
  `created_by` / `updated_by`, timestamps, soft deletes, and covering indexes on
  `(college_id, …)` for the list filters.

## Tenancy

* All four models use `BelongsToCollege`, so every query runs under
  `CollegeScope` (which resolves to `1 = 0` without a tenant) and `college_id`
  is stamped from `TenantContext`. The Form Requests strip `college_id`,
  `created_by`, `updated_by` (and `name_normalized`) from the payload; the
  services stamp them from the tenant context and the authenticated user.
* Controllers resolve models with `Model::query()->findOrFail($id)` under the
  active tenant — never implicit route model binding — so a foreign-tenant id
  is a plain 404 for `edit` / `update` / `destroy` / `show`.
* Foreign keys are validated **contextually**: a book's category, publisher and
  every credited author must be active (not soft-deleted) rows of the active
  college (`Rule::exists(...)->where('college_id', …)->whereNull('deleted_at')`),
  and `BookService` re-checks the same rule before every write, so a forged
  cross-tenant id is rejected even if the Form Request were bypassed.
* The option lists (`LibraryFormOptions`) are read through the scoped models,
  so a form can only ever offer the active college's masters.

## Data rules

* **Codes** (`book_categories.code`, `books.code`) are stored upper-cased and
  trimmed; at most one *active* (not soft-deleted) row per `(college_id, code)`,
  enforced by a partial unique index on SQLite/PostgreSQL and by the service
  guard everywhere (`ValidationException` on `code`). Soft-deleted rows never
  block re-using a code — exactly like `fee_categories`.
* **ISBN** is optional (theses, local prints and journals may have none). When
  supplied it is normalized (`Isbn::normalize` — digits plus a trailing `X`,
  hyphens/spaces removed, upper-cased) before validation and storage, must have
  the structure of an ISBN-10 or ISBN-13 (`ValidIsbn`; check digits are not
  enforced because misprinted ones exist on real books), and is unique among the
  college's active books — `978-0-262-03384-8` and `9780262033848` are the same
  ISBN. `NULL` never collides, so any number of ISBN-less books is fine.
* **Author / publisher names** are unique among the college's active rows,
  compared on `name_normalized` (service guard + partial unique index).
* **Deleting** soft-deletes. A category, author or publisher that books still
  reference cannot be deleted (`ValidationException`, shown as an error; mark it
  inactive instead). Deleting a book keeps its masters and its `author_book`
  rows (for the audit trail); the soft-deleted book no longer counts as "in use".
* `publication_year` is `1000 … current year + 1`; `status` is `active` /
  `inactive` everywhere.

## Audit

`books.created|updated|deleted`, `book_categories.*`, `authors.*` and
`publishers.*` are written through the shared `AuditLogService`, with
`college_id`, `user_id`, subject morph and old/new snapshots. Book snapshots
include the ordered `author_ids`, so re-crediting authors is visible in the
log. `created_by` / `updated_by` are stamped on every row.

## Dashboard

`LibraryDashboardService` aggregates live, through the scoped models: totals
(books / categories / authors / publishers with active counts, added this
month, books without ISBN), books per category (including empty categories),
most-credited authors, publishers by titles, books per language and per
publication decade, and the most recently catalogued titles. **No dashboard or
summary tables exist** — nothing can drift from the masters or leak across
colleges. Access requires `library_dashboard.view` (holding every other library
permission is not enough); cross-links on the page are themselves gated on the
target screen's `view` permission.

## RBAC and navigation

Each action is gated on its own slug through the policy
(`User::hasPermission(slug, $model->college_id)`); a user holding one
permission never gains another, and a permission granted in another college
never authorises an action in the active one. The sidebar section renders only
when the user holds at least one library `view` permission; each entry is gated
individually, and *Authors / Publishers* points at Authors (or at Publishers
when only `publishers.view` is held). The Authors and Publishers screens link
to each other through permission-gated tabs.

## Deploying this phase (non-destructive)

```bash
git pull origin main
php artisan migrate --force     # five additive migrations, new tables only
php artisan db:seed --force     # idempotent: registers the 17 library slugs
```

A database seeded before these slugs existed silently hides the module (every
policy check returns `false`); the re-seed above fixes it without touching any
existing row. Verify with:

```bash
php artisan tinker --execute="\App\Models\Permission::whereIn('module', ['library_dashboard','books','book_categories','authors','publishers'])->count();"
```

The partial unique indexes are created on SQLite and PostgreSQL only;
MySQL/MariaDB rely on the service guards (which run on every engine), the same
approach the Finance module takes.

## Out of scope (next phases)

Book Copies (accession numbers, shelf location, condition, availability),
Library Members, Issue / Return, Renewals, Fines and Library Reports. None of
their tables, permissions or navigation entries exist yet; the book master
above is designed so that copies can reference it without changing any column
described here.
