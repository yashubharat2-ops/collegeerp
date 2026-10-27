# Student Filter System

One reusable filter implementation, shared by every existing student-related screen.
Nothing here creates students, duplicates master data, or restores a removed module.

## Where it lives

| Piece | File |
| --- | --- |
| Field/label/request-key registry | `config/student-filter.php` |
| Validation + query semantics | `app/Domain/Student/Filters/StudentFilter.php` |
| Student list pipeline (search, sort, pagination, export) | `app/Domain/Student/Services/StudentListService.php` + `app/Support/Listing/ListQueryBuilder.php` |
| Shared Blade panel (collapsible, responsive) | `resources/views/components/student-filter.blade.php` |
| Dependent dropdowns (progressive enhancement) | `public/js/erp-student-filter.js` |
| Secure identity search (Aadhaar / ABC ID) | `app/Http/Controllers/StudentIdentityFilterController.php` + `app/Domain/Student/Support/StudentIdentityFilterStore.php` |
| Bulk actions (existing) | `App\Support\BulkAction\*`, `App\Domain\Student\BulkActions\*`, `public/js/erp-list.js` |

Every module calls `StudentFilter::fromRequest()` (or `fromArray()` for a
bulk-action payload), applies one of the `applyTo*()` methods to **its own**
tenant-scoped query, and renders `StudentFilter::viewData()`. There is one
implementation, not one per module.

## Modules using it

Students (list, CSV/PDF/print export), Student Enrollments, Student Documents
(+ document pack), Student ID Cards (+ batch), Student Reports, Student Result
History, Fee Collections, Fee Dues, Student Fee Assignments (list + allocation
preview), Marksheets, Grade Cards, Results, Exam Attendance, Exam Marks, Exam
Reports, Examination Reports, Academic Reports, Academics → Attendance,
Academics → Subject Enrollments, Admissions and Admission Applications (the
canonical subset only), HR/other modules keep their own vocabularies.

## Fields

| Field | Request key(s) | Match | Source |
| --- | --- | --- | --- |
| REG.NO | `student_number` | contains | `students.student_number` (the existing registration number — no duplicate column) |
| REG. DATE | `admission_date_from`, `admission_date_to` | inclusive range | `students.admission_date` |
| ACADEMIC STATUS | `academic_status` | derived (below) | `student_academic_records` + `students.status` / `students.registration_submitted_at` / `student_transfers` |
| STUDENT STATUS | `student_status` | exact | `students.status` (control offers Active / Inactive) |
| PROGRAM / CLASS | `program_id` | exact | `programs` (college-scoped) |
| SEMESTER / SECTION | `academic_context` (`term:<id>` / `section:<id>`) | exact, same enrollment | `academic_terms`, `sections` (college-scoped) |
| NAME OF STUDENT | `student_name` | contains | first / middle / last name |
| AADHAAR NUMBER | vault only (POST) | exact (12 digits → HMAC, 4 digits → last four) | `students.aadhaar_hash` / `students.aadhaar_last4` |
| ABC ID / APAAR ID | vault only (POST) | exact | `students.apaar_id` |
| E-MAIL, MOBILE, ENROLLMENT NO., FATHER / MOTHER NAME | existing keys | contains / exact | existing columns |

Removed from the panel (data is untouched): Blood Group, Date of Birth,
Unique Student ID (it duplicated REG.NO), Nationality, Legacy IDs 1–4 (which
never existed as columns). `enrollment_status` is still accepted for existing
links and module tests but is no longer drawn as a control.

### Academic Status mapping

There is no single academic-status column, but the existing progression ledger
`student_academic_records` already stores `academic_status`
(enrolled / passed / failed / withdrawn / discontinued), `promotion_status`
(… / transferred) and `completion_status`. Each offered value is a real predicate
that reads that ledger together with the student lifecycle columns — so a student
whose ledger is complete and one whose ledger is not yet filled in are both found:

| Value | Predicate |
| --- | --- |
| `continue` | `students.status = 'active'` OR a record with `academic_status = 'enrolled'` |
| `dropout` | a `discontinued` / `withdrawn` record that was not transferred out, OR `students.status = 'withdrawn'` with no approved transfer / issued TC |
| `pass_out` | `students.status = 'graduated'` OR a record `passed` / `completion_status = 'completed'` |
| `new_registration` | `students.registration_submitted_at IS NOT NULL` (created through the existing single or bulk registration workflow) |
| `transfer_out` | an approved transfer request or issued TC exists, OR a record with `promotion_status = 'transferred'` |

Not offered, because the data does not exist (listed in
`config('student-filter.academic_status_gaps')` and reported in the panel):

* `online_registration` — the single-registration form and the bulk importer
  write the same fields; nothing marks a registration as online.
* `transfer_in` — only transfer-out records exist (`student_transfers`).

### Batch

There is no separate Batch table in this application: a **Section** is the
section / batch cohort (the Sections screen is titled “section / batch” and
`StudentEnrollment` documents `section_id` as “Section / Batch”). The
SEMESTER / SECTION control is therefore also the Batch filter, and its options
carry `data-program` / `data-year` so the dropdown narrows with Program and
academic year. No duplicate batch system was introduced.

## Semantics

* All filters are AND-ed; each filter changes the database query.
* Search (`search`) and filters combine.
* Pagination and sort links keep the full query string (`ListQueryBuilder`).
* If no filter is supplied, the ordinary authorized list is shown.
* A reversed date range, a foreign/incompatible section+program+semester
  combination is a validation error, not a silently broadened query.
* Unavailable/legacy parameters are ignored, never partially applied.

## Security

* Every root query carries `CollegeScope` (`TenantContext`), so options and rows
  never cross colleges. A submitted `college_id` is never read.
* Aadhaar / ABC ID searches require `students.view_sensitive_identity`
  (`config('student-filter.sensitive_permission')`) **and** the right to open the
  Student list (`students.view`), because the search is stored against the
  Student list and the POST endpoint re-checks both. The control is hidden
  without them and the server returns 403.
* Identity values are submitted by **POST** and stored in a session vault bound
  to the user, the college and a 60-minute TTL. The URL carries only an opaque
  token; the page, the applied-filter chips, the audit log and the CSV export
  contain only the fact that a search was applied.
* Aadhaar is never decrypted for a search: a 12-digit value is converted to the
  same keyed HMAC stored in `students.aadhaar_hash` (and structurally validated
  first, including the Verhoeff checksum), a 4-digit value matches
  `students.aadhaar_last4`.
* Rejected or expired tokens read as “no identity filter” and never disclose why.

## Bulk actions

The existing actions are preserved (Export → Excel/PDF/Print, Generate ID cards,
Bulk documents; plus each module’s own export). Selection is current-page only:
“Select all” ticks this page’s rows, the bar states that scope explicitly, and
`BulkActionController` re-queries every id inside the college scope, re-checks the
action permission and per-record policy before doing anything. A follow-up URL is
built server-side from the ids the handler authorized.

## Tests

| Suite | Covers |
| --- | --- |
| `tests/Feature/Students/StudentFilterSystemTest.php` | every required field changes the query; Academic vs Student Status independence; program/section dependency and rejection of impossible combinations; authorized Aadhaar + ABC ID; combined filters; search + filters; pagination preservation and reset; single-registration and bulk-import records; bulk actions on an explicit selection only; attendance/exam screens unchanged |
| `tests/Feature/Students/CanonicalStudentFilterTest.php` | shared request names, no duplicate status controls, removed fields, identity privacy (page, URL, audit, export), same-enrollment semantics, pagination/sort/export reuse, tenant isolation |
| `tests/Feature/Students/StudentListFiltersTest.php` | legacy list contract kept intact: search fields, profile filters, sorting allow-list, pagination links, tenant scoping, one date range |
| `tests/Feature/Students/StudentFilterModuleIntegrationTest.php` | the same filters on Enrollments, Documents, ID cards, Admission lists, and section context without duplicate controls |
| Module suites (`ExamAttendance`, `ExamMarks`, `StudentReports`, `ExamReports`, `ExaminationReports`, `AcademicReports`, `BulkAction`) | existing per-module behaviour, labels and single-control counts are unchanged |

## Limitations

* **Batch** has no table of its own; it is the Section cohort (see above).
* **Online Registration** and **Transfer In** academic statuses have no backing
  data and are not offered.
* **ABC ID uniqueness** is not enforced by the schema; the filter is an exact
  match, not a duplicate check.
* **Aadhaar partial search** is limited to the stored last four digits; there is
  no way to search on the full number without knowing it (by design).
* Academic Status reads the progression ledger where it exists. Students created
  before the ledger was introduced are matched through the `students.status`
  columns instead; a student whose only signal is a ledger row that has not been
  written yet is therefore indistinguishable from a lifecycle-only student.
* `enrollment_status` remains a supported request parameter for old links but is
  no longer a visible control.

## Out of scope

The removed Admission module, Admission Application module and the
admission-to-student conversion workflow were **not** restored, recreated or
reintroduced. The two admission *list* screens that exist today keep working and
use only the canonical subset of fields (`student_name`, `email`,
`mobile_number`, `academic_year_id`, `program_id`); their own workflow status
control is untouched.
