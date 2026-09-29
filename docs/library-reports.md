# Library Reports (read-only)

`/library-reports` is the sixth entry in the REPORTS sidebar section, after HR
Reports (the section stays in its approved position, immediately after
Inventory / Asset Management). The single `library_reports.view` permission
grants all eight views. It does **not** grant access to the operational Library
pages (dashboard, books, categories, authors / publishers, copies, members,
issue / return, renewals, fines) and those pages' permissions do not grant the
reports. Only one GET route exists; no report tables, snapshots, exports or
cached aggregates are created. Library Reports also left the Library Management
sidebar group, which keeps its nine operational entries.

| View | Source / interpretation |
| --- | --- |
| Books Report | `books` rows (active / inactive) with their category, publisher, credited authors and live copy counts (total, available, issued). Filters: category, author, publisher, book status, free-text search. ISBN search accepts the hyphenated form and matches the canonical digits the Library stores (`Isbn::normalize`). |
| Book Copies Report | `book_copies` rows with the book / category they belong to, accession number, barcode, location, copy number, condition and stored status. `availability` is **derived** from the stored status and adds no state: available = `available`, on loan = `issued`, unavailable = `lost` / `damaged` / `withdrawn`. Filters: book, copy status, availability, search. |
| Library Members Report | `library_members` rows with the Student Enrollment / student they reference (the Library keeps no second identity), validity and loan counts: all loans, open issues and open issues past their due date. Filters: member status, student enrollment, search (member code, enrollment number, student name / number). |
| Issue / Return Report | `library_transactions` rows (issued, returned, lost) with copy, title, member, issue / due / return dates and the renewal count recorded against them. The date window is the **issue date**. Filters: book, member, transaction status, date range, search. |
| Overdue Books Report | Circulation rows that are overdue by the Library's own definition — still issued past the due date (`LibraryTransaction::isOverdue()`), returned after the due date, or lost after it. Days overdue are displayed from the stored dates; no fine is calculated or raised here. The date window is the **due date**. Filters: book, member, transaction status, date range, search. |
| Fine Report | `library_fines` rows exactly as the fine service wrote them (type, period, days, rate, assessed / paid amounts, payment reference). Headline figures come from one grouped query; outstanding = assessed − paid, mirroring `LibraryFine::outstanding()`. The report never recomputes an amount. The date window is the **fine period**. Filters: member, book, fine status, date range, search. |
| Lost / Damaged Books Report | `book_copies` whose stored status is `lost` or `damaged`, with title, category, condition and the last circulation record (member, issued / returned dates). The date window is the **acquired date** and the member filter narrows through the existing transaction rows. |
| Library Summary | Live totals for the whole college: books (total / active / inactive), copies (total / available / issued / lost / damaged / withdrawn), members (total / active / inactive / suspended / expired / past expiry), circulation (records / issued / returned / lost / current overdue / renewals), fines (counts by status, assessed / paid / outstanding) and lost-damaged copies. No filters: the summary always describes the active college. |

Filters (only those relevant to the selected view are shown and applied; any
other query parameter is ignored, and a value a view cannot use is rejected only
where that view actually uses the filter).

Implementation notes:

* `App\Domain\Library\Services\LibraryReportService` builds every root query,
  correlated count and dropdown from models carrying `CollegeScope` (and
  `SoftDeletes` where the model has it), so rows and counts never cross colleges
  and soft-deleted masters are excluded. Circulation history
  (`library_transactions`, `library_renewals`, `library_fines`) has no soft
  deletes and is therefore never hidden — exactly as the operational screens
  show it. An ID from another college in a filter simply matches nothing.
* No Library rule is re-implemented: circulation statuses, member eligibility,
  copy availability and fine amounts are the ones the operational Library
  services stored. The only derived values are the availability bucket (from the
  stored copy status) and the displayed days overdue (from the stored dates).
* Lists paginate 20 per page with a unique-ID tiebreak and keep the query string;
  ordering is deterministic (title, accession number, member code, dates, id).
  Counts and sums are SQL aggregates and every relation a row renders is eager
  loaded, so the query count does not grow with row counts (a test asserts this
  for every view).
* Views use the existing `.print-area` / `.no-print` styles; printing shows the
  current page only. No bulk export is introduced in this phase.
* Tests: `tests/Feature/LibraryReports/LibraryReportsTest.php` covers RBAC
  separation, the fixed eight views and their filters, sidebar placement, tenant
  isolation with forged foreign filter IDs, every relationship, soft deletes,
  pagination, empty / invalid / irrelevant filters, the GET-only read-only
  contract and the query-count guard. `LibraryNavigationTest` and
  `HrReportsTest` pin the sidebar movement.
