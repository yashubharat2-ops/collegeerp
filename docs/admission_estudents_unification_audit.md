# Admission ⇄ eStudents Unification — Pre-Implementation Audit

**Date:** 2026-10-25
**Branch:** `arena/dd3f6d82-collegeerp` (base `a0c20e4`)
**Status:** AUDIT ONLY — no application code, schema, route, permission, view or test was modified. No migrations were run. Nothing was pushed.

**Method:** static read of `routes/web.php`, `app/Domain/Admission/**`, `app/Domain/Student/**`, `app/Services/Approval/**`, the 11 `Admission*Controller`s and 12 `Student*Controller`s, the 9 admission + 6 student migrations, `database/seeders/DatabaseSeeder.php`, `resources/views/layouts/sidebar.blade.php`, the 37 admission / 67 student Blade views, and the 33 admission / 34 student / 1 approval feature tests.

> Note: `php` and `vendor/` are not present in this sandbox, so the suite could not be executed. All findings below are from source reading; test *expectations* were read, not run.

---

## 1. Module inventory

### 1.1 Admission

| Layer | Contents |
|---|---|
| Tables (9) | `admission_applicants`, `admission_enquiries`, `admission_applications`, `admission_document_types`, `admission_documents`, `admission_document_type_program` (pivot), `admission_merit_lists`, `admission_merit_entries`, `admissions` |
| Controllers (11) | Applicant, Enquiry, Application, Document, DocumentType, MeritList, MeritEntry, Admission (final), Dashboard, Report, StatusType |
| Models (8) / Policies (8) | one per table (pivot has no model) |
| Domain (1,667 LOC) | 5 Actions (`CreateApplication`, `CreateEnquiryWithApplicant`, `Generate{Enquiry,Application,Admission}Number`) · 8 BulkActions · 7 Services (`AdmissionService`, `AdmissionApplicationWorkflow`, `AdmissionApplicationStatusService`, `AdmissionDocumentService`, `AdmissionConversionGuard`, `AdmissionConversionLock`, `DuplicateApplicantDetector`) |
| Views (37) | 8 CRUD screen sets + dashboard + reports + status types |
| Permissions (33) | `admission_applicants.*`, `admission_enquiries.*`, `admission_applications.*`, `admission_documents.*` (+`.verify`), `admission_document_types.*`, `admission_merit.*` (+`.publish`), `admissions.*`, `admission_dashboard.view`, `admission_reports.view`, `admission.status_types.view` |
| Tests (33) | `tests/Feature/Admissions/*` — management, tenancy, authorization, bulk, workflow, conversion, cancel-guard, number/duplicate, merit, documents, dashboard, reports |

### 1.2 eStudents

| Layer | Contents |
|---|---|
| Tables (6) | `students` (+2 additive migrations: `category`; 24 profile/guardian/identity columns), `student_enrollments` (+`section_id`), `student_academic_records`, `student_documents`, `student_promotions`, `student_transfers` |
| Controllers (12) | Student, Enrollment, AcademicRecord, Document, IdCard, Import, Promotion, Transfer, History, ResultHistory, Report, FeeAssignment |
| Domain (~5,100 LOC) | 6 Actions (`ConvertApplicationToStudent`, `ConvertAdmissionToStudent`, `Generate{Student,Enrollment,Tc}Number`, `PromoteStudent`) · 8 BulkActions · 11 Services (`StudentService` 552, `StudentImportService` 640, `StudentFilter` 765, history/report/document/transfer/photo/idcard/list/pack) · `Aadhaar`, `SensitiveIdentity`, `ValidAadhaar` |
| Views (67) | students (index/show/create/edit/import/export_report), enrollments, academic records, documents (+batch), ID cards (+batch), promotions, transfers, history, result history, 11 report screens |
| Permissions (34) | `students.*` (+`.export`, `.view_sensitive_identity`), `student_enrollments.*`, `student_academic_records.*`, `student_documents.*`, `student_id_cards.*`, `student_promotions.*` (+`.approve`), `student_transfers.*` (+`.approve`), `student_history.view`, `student_reports.view`, `student_fee_assignments.*`, `student_result_history.view` |
| Tests (34 + 1 unit) | `tests/Feature/Students/*`, `tests/Unit/Students/AadhaarTest` |

### 1.3 Shared machinery both modules already use

- **Central Approval Management** (added 2026-10-17): `approval_requests` + `approval_settings`, `ApprovalRegistry` (allowlist), `ApprovalService` (transactional submit/approve/reject/cancel, self-approval blocked, duplicate-pending blocked, side-effects, audit), `ApprovalController`, 2 policies, 5 views, 1 test.
- **Audit**: `AuditLogService::record()` → append-only `audit_logs` (no `updated_at`, sensitive-key redaction).
- **Tenancy**: `BelongsToCollege` + `CollegeScope` + `TenantContext`; cross-college reads 404, never 403.
- **Documents**: one master, `admission_document_types`, consumed by **both** `admission_documents` and `student_documents`.
- **Listing/bulk/export**: `ListQueryBuilder`, `ListContext`, `ListSelection`, `CsvStreamExport`, `BulkActionRegistry`.
- **Numbering**: college-row-locked generators (`ENQ-`, `APP-`, `ADM-`, `STU-`, enrollment).

---

## 2. Admission vs eStudents — feature comparison

| Capability | Admission | eStudents | Verdict |
|---|---|---|---|
| Person identity capture | `admission_applicants`: 10 fields (name×3, email, phone×2, gender, DOB, single `address` text, status) | `students`: ~45 fields — structured address×6, category, photo, guardian block×8, Aadhaar (encrypted + `last4` + HMAC `aadhaar_hash`), APAAR, govt-ID pair, emergency contact, previous-school block×5, blood group, nationality, mother tongue, remarks | **Duplicate, asymmetric.** Student is a strict superset. Applicant cannot hold guardian or identity data at all |
| Course / program | `admission_applications.program_id` (required) | `student_enrollments.program_id` (optional) | Duplicate |
| Academic session | `admission_applications.academic_year_id` (required) | `student_enrollments.academic_year_id` (required) | Duplicate |
| Section / batch | ✗ not modelled | ✓ `student_enrollments.section_id`, year+program validated | **Missing in Admission** |
| Registration number | `enquiry_number`, `application_number`, `admission_number` (3 generators) | `student_number`, `enrollment_number`, TC number (3 generators) | **Duplicate — 6 numbers for one admission event** |
| Required documents | ✓ `admission_document_types` (+ per-program pivot `is_required`) | ✓ same master, per-student rows | Master shared; **stores duplicated** |
| Document upload | ✓ `admission_documents` (private disk, server-generated path) | ✓ `student_documents` (private disk, server-generated path) | **Duplicate. No carry-over at conversion — files must be re-uploaded** |
| Document verification | ✓ pending/verified/rejected + `verified_by`/`verified_at`/`rejection_remarks` | ✓ identical columns & lifecycle | **Duplicate** |
| Document checklist enforcement | Pivot stores per-program `is_required` but **nothing blocks submission** | `StudentDocumentPackService` computes missing required types — **report only, never a gate** | **Missing in both** |
| Enquiry / lead tracking | ✓ `admission_enquiries` (source, follow-up, 6 statuses, bulk status, duplicate-check endpoint) | ✗ | Admission-only (genuine) |
| Merit / selection | ✓ `admission_merit_lists` + `_entries` (score, rank, selected/waitlisted/rejected, publish/unpublish) | ✗ | Admission-only (genuine) |
| Admission confirmation | ✓ `admissions` (`admission_number`, `admission_date`, active/cancelled/completed) | ✓ `students.admission_date` + `student_enrollments` (enrollment_number, enrollment_date, 4 statuses) + `admission_applications.status='admitted'` | **Triple representation of one fact** |
| Status workflow engine | ✓ `AdmissionApplicationWorkflow` (7 statuses, transition map) + `AdmissionApplicationStatusService` (shared by single edit & bulk review) | ✗ none on Student; `StudentEnrollment` has terminal-state guards inside `StudentService::updateEnrollment` | **Missing in eStudents** |
| Approval routing | ✓ wired in `AdmissionApplicationController@store`/`@update` | ✗ `ApprovalRegistry` lists `student.student_data_change` but **no caller exists** | **Missing in eStudents** |
| Duplicate detection | `DuplicateApplicantDetector` (phone/alt-phone/email, same college, advisory only, exposed via `admission-enquiries/duplicate-check`) | `Rule::unique` on phone per college + `rejectDuplicateAadhaar` HMAC check (hard blocks) | **Two disjoint regimes; nothing links an applicant to an existing Student** |
| Bulk / CSV import | ✗ | ✓ `StudentImportService` (640 LOC): template, upload, validate-then-import, two-phase, per-row errors, in-file duplicate detection | **Missing in Admission** |
| Bulk actions | 8 handlers (export, review, cancel, complete, enquiry status) | 8 handlers (export, section, status, documents, ID cards, PDF, print, report) | Parallel, both fine |
| 360° profile page | ✗ (no `show` on any admission resource) | ✓ `students.show` with tabs (profile/enrollments/documents/history/…) | **Missing in Admission** |
| ID cards | ✗ (sidebar entry points at the *Student* ID-card screen with `?context=admission`) | ✓ `StudentIdCardService` + single/batch print | Admission borrows it |
| History / timeline | ✗ | ✓ `StudentHistoryService` (428 LOC, derived, read-only) | **Missing in Admission** |
| Promotion / transfer / TC | ✗ | ✓ full request→approve→issue lifecycle | **Missing in Admission** |
| Reports | `AdmissionReportController` — 5 sections (status summary, program-wise, year-wise, documents, merit, admissions) | `StudentReportController` — 11 reports, **including `admissions`** (`StudentReportService::admissions()` reads the `admissions` table) | **Duplicate report on the same table** |
| Dashboard | ✓ `AdmissionDashboardController` (counts by status/program/year, document verification) | ✗ (only the global `DashboardController`) | Admission-only |
| Navigation | **2 of 10 screens linked** | 9 of 9 linked | see §3.1 |

---

## 3. Findings

### 3.1 Eight admission screens are orphaned from navigation (highest-impact usability defect)

`resources/views/layouts/sidebar.blade.php` lines 90–103 render the Admission group as exactly: Applications, New Registration, Student List (`students.index?admission_filter=1`), Transfers ▸ In/Out, Status Types, ID Cards (`?context=admission`), Settings ▸ ID Card Setting, Reports.

Routed, permissioned, tested and **unreachable from the UI** (verified: every `route()` reference to them is a self-referential Cancel/Clear/filter-form action inside their own Blade files):

`admission-applicants.*` · `admission-enquiries.*` · `admission-documents.*` · `admission-document-types.*` · `admission-merit-lists.*` · `admission-merit-entries.*` · `admissions.*` · `admission.dashboard` (**0 references anywhere**)

Consequence: the documented as-is journey is impossible to complete by clicking. **"New Registration" (`admission-applications.create`) offers a `<select>` of the 50 most recent applicants — and there is no menu path to create one.** An operator must know the URL.

### 3.2 The real as-is journey (7–9 screens for one admission)

1. `admission-applicants/create` — 10 person fields *(URL only)*
2. `admission-enquiries/create` — optional *(URL only)*
3. `admission-applications/create` — pick applicant + year + program + status *(sidebar "New Registration")*
4. `admission-documents/create` — one screen **per file** *(URL only)*
5. `admission-merit-lists` / `-entries` — optional *(URL only)*
6. Status walk `submitted → under_review → approved`; if the actor lacks `approvals.approve`, `ApprovalService` raises a request and a **different** user decides it in Approval Management
7. `admissions/create` — the final Admission row *(URL only)*
8. Convert — **two divergent paths**:
   - `admissions/{id}/convert` → prefills the full 6-section Student form → `ConvertAdmissionToStudent` (complete profile)
   - `students/convert/{application_id}` → a bare POST → `ConvertApplicationToStudent` (name/email/phone/gender/DOB/address only; **guardian, Aadhaar, category, photo all silently blank**)
9. Student + first Enrollment exist

Both paths are idempotent, lock-ordered (Application → College → Student/Enrollment) and refuse cancelled admissions — the concurrency engineering here is good and must be preserved.

### 3.3 Duplicate features

1. **Two person records per human.** `ConvertApplicationToStudent` *snapshots* applicant → student by design (documented in `2026_09_18_000001`). The applicant row survives, permanently holding a stale 10-field subset.
2. **Two document stores over one type master.** `admission_documents` and `student_documents` have near-identical columns (`file_path`, `original_filename`, `mime_type`, `file_size`, `verification_status`, `verified_by`, `verified_at`, `rejection_remarks`, `uploaded_by`). Grep confirms **no code carries an admission document into a student document** — only `AdmissionDocumentType` (the master) is shared. Verified documents are re-uploaded and re-verified after conversion.
3. **Three confirmations of "admitted".** `admission_applications.status='admitted'` + an `admissions` row + `students.admission_date`/`student_enrollments`. `AdmissionService::createFromApplication` writes the first two; conversion writes the third. They can disagree — `AdmissionConversionGuard` exists precisely because cancelling an `admissions` row deliberately leaves the application reading `admitted`.
4. **Six number generators** for what the client wants to be one event.
5. **Two conversion code paths** producing different data completeness (§3.2 step 8).
6. **Two admission reports** — `admission-reports.index` and `student-reports?report=admissions`, both over the `admissions` table.
7. **Two sidebar ID-card entries** differentiated only by `?context=admission`, which `DELIVERY_REPORT.md` states was done to satisfy `test_sidebar_no_duplicate_entries` rather than because the destinations differ.
8. **Cross-module filter coupling** — `App\Domain\Student\Filters\StudentFilter` (765 LOC) is injected into `AdmissionApplicationController`, `AdmissionController` and others via `applyToAdmissionRecord()`. A Student-domain class drives Admission queries.

### 3.4 Missing functionality vs the target workflow

| Target step | Present today? | Gap |
|---|---|---|
| 1. One registration form: student + guardian + course/program + session + required documents | **No** | The rich form exists (`students/_form.blade.php`, 666 lines, 6 sections) but is *post-approval only* and creates an immediately-**active** student. The reachable "New Registration" form has none of these fields. Documents are never on either form |
| 2. Pending admin approval + notify the administrator | **Partly** | Approval engine works for `admission.application_submit`. But `Student::STATUSES = active, inactive, graduated, suspended, withdrawn` — **there is no pending/registration state on a student**. And `ApprovalService::notifyApprovers()` is an explicit **no-op**; the Communication module (`CommunicationNotificationService`, `communication_notifications`) exists and is never called from approvals |
| 3. Approve / Reject / **Request Corrections** | **Partly** | Approve ✓, Reject ✓ (`ApprovalRequest::STATUSES = pending, approved, rejected, cancelled`). **"Request Corrections" does not exist.** Rejection appends free text to `admission_applications.remarks` (`handleRejectionSideEffect`) — no structured correction list, no resubmission counter, no re-submit path other than the generic `rejected → draft/submitted` transition |
| 4. Admission confirmed → student available in ERP modules | **Yes, manually** | Two hand-run conversion routes; neither is triggered by approval |
| No separate enrollment approval stage | ✓ satisfied | No enrollment approval exists today and none must be added — enrollment is created as a *side effect* of `createStudent`/`createEnrollment` |
| Clear registration vs admission-approval status | **No** | Conflated across three tables (§3.3.3) |
| Prevent duplicate student records | **Partly** | Strong *within* Students (unique phone per college, Aadhaar HMAC, enrollment tuple guard in `StudentService::hasNonDeletedEnrollmentForTuple`). Weak *across* modules: nothing links an `admission_applicant` to an existing `Student`, so the same person can be registered as an applicant **and** created directly |
| Audit trail of approval decisions | **Yes** | `approval.requested/approved/rejected/cancelled` + `admission_application.status_changed` in append-only `audit_logs`; `decided_by`/`decided_at`/`decision_reason` on the request |

### 3.5 Two concrete correctness defects found while auditing

1. **`admission.admission_create` is a dead approval action.** It is declared in `ApprovalRegistry::MODULES`, so `ApprovalController@settings` renders a live toggle for it on the Approval Settings screen — but **no code path ever calls `submitRequest()` for it**, and `ApprovalService::applyApproval()` handles it with the comment *"Future: … not needed yet"*. An administrator can enable "Create Admission requires approval" and nothing changes. `AdmissionController@store` writes the admission unconditionally.
2. **An admission can be confirmed before it is approved.** `AdmissionService::createFromApplication` blocks only `draft`, `rejected`, `cancelled` — it explicitly permits `submitted` and `under_review`. This directly contradicts the target workflow, where confirmation must follow approval.

---

## 4. Cross-module dependencies

### 4.1 The decisive architectural fact

> **Nothing outside the Admission module references `admission_applications` or `admissions`.**

Verified by grepping every migration for `admission_application_id` / `admission_id` / `applicant_id` outside the 2026-09-16 admission set: the only external reference in the entire schema is **`students.admission_application_id`** (nullable, `nullOnDelete`) — a provenance pointer, not a structural dependency.

The Admission tables are a **self-contained island with exactly one exit**. Collapsing the workflow therefore cannot break a foreign key in Fees, Exams, Library, Hostel, Transport, Academics, HR or Certificates.

### 4.2 `student_enrollments` is the real integration hub

`student_enrollment_id` is the FK anchor for: `student_fee_assignments`, `fee_payments`, `exam_attendances`, `exam_marks`, `exam_results`, `library_members`, `student_transport_assignments`, `hostel_allocations`, `hostel_attendances`, `academic_subject_enrollments`.

**Consequence:** "admission confirmed" *must* produce a `StudentEnrollment`, or every other ERP module is unreachable for that student. Equally useful: **a registration that has no enrollment yet is invisible to all of them.** That is the natural, already-existing gate for a pending-approval state — no downstream query has to change.

### 4.3 Verified: no module filters on `students.status = 'active'`

Grep of Finance / Library / Hostel / Transport / Inventory / Communication / Certificates found **zero** `students.status` filters. Four places do list Students directly for pickers/reports and would surface a pending registration:

- `App\Domain\Finance\Support\FeeFormOptions::students()` (limit 500, no status filter)
- `App\Domain\Inventory\Support\InventoryFormOptions`
- `FinanceReportController@264`, `Hostel\HostelReportController@232`

These are the only integration points needing a `registration_status` guard.

### 4.4 Other dependencies to respect

| Dependency | Direction | Constraint |
|---|---|---|
| `student_documents.document_type_id` → `admission_document_types` | Students → Admission | **`admission_document_types` must never be dropped or renamed**; the Students module depends on it. Deliberate reuse, documented in `2026_09_19_000008` |
| `admission_document_type_program` | Admission → Academics | Per-program required-document config; currently unused as a gate |
| Academic masters (`academic_years`, `programs`, `sections`, `departments`) | both → Academics | Registration form pickers. `sections.program_id` is NOT NULL → a program-less enrollment can never take a section (`StudentService::SECTION_REQUIRES_PROGRAM`) |
| `audit_logs` | both → Foundation | Append-only; no `updated_at`; redacts sensitive keys |
| Reports | `AdmissionReportController`, `StudentReportService::admissions`, `ConsolidatedReportService`, `DashboardController` all read admission tables | Freezing the tables freezes these reports unless they are re-pointed |
| Tests | `SidebarStructureTest` **hard-asserts** the Admission group (9 links, exactly 2 nested groups, Reports last) and the Students group (9 links); `AdmissionSidebarAndApprovalRegressionTest` asserts 12 routes stay 200 and no duplicate sidebar hrefs | Any navigation change **must** update these tests in the same commit |
| Fees | `student_fee_assignments` needs an enrollment | Admission approval → enrollment → fee assignment becomes possible; nothing auto-assigns fees today (out of scope) |

---

## 5. Recommended simplified workflow

### 5.1 Design principle

**One front door, one person record, one approval decision.** The existing rich Student form becomes the registration form; the Admission tables are retained as an automatically-written back-office register and are never hand-keyed again.

This is achievable with **one additive migration** and **no new table**, because §4.1 and §4.2 show the enrollment is already the gate every other module respects.

### 5.2 Target flow

```
┌─ 1. Student Registration Form ───────────────────────────────────────┐
│  Reuse resources/views/students/_form.blade.php (all 6 sections:      │
│  Academic/admission · Basic · Parent/guardian · Contact · Additional) │
│  + a Documents section (required types from                           │
│    admission_document_type_program for the chosen program)            │
│  Writes in ONE transaction:                                           │
│    students           registration_status = 'pending_approval'        │
│                       status = 'inactive'   (no enrollment yet)       │
│    student_documents  one row per upload                               │
│    approval_requests  module 'student', action 'registration_approval'│
│  Duplicate guard: existing phone / aadhaar_hash / (name+DOB) check     │
│  Notification: CommunicationNotificationService → holders of           │
│    'approvals.approve' in this college                                 │
└───────────────────────────────────────────────────────────────────────┘
                              ↓
┌─ 2. Pending Admin Approval ──────────────────────────────────────────┐
│  Existing screens, untouched: approvals/pending · my-requests ·       │
│  history · show. Self-approval already blocked, duplicate-pending     │
│  already blocked, row-locked, audited.                                │
└───────────────────────────────────────────────────────────────────────┘
                              ↓
┌─ 3. Admin Review — three decisions ──────────────────────────────────┐
│  APPROVE            → registration_status='approved', status='active',│
│                       create first StudentEnrollment via the existing │
│                       StudentService::createEnrollment (tuple guard,  │
│                       college lock, section/year/program validation), │
│                       then write the register projection of §8.2      │
│                       (applicant → application → admission, via the   │
│                       existing generators + AdmissionService) so the  │
│                       admission register and its reports keep working │
│  REJECT             → registration_status='rejected'; student stays   │
│                       'inactive', never enrolled, invisible to ERP    │
│  REQUEST CORRECTIONS→ registration_status='correction_requested' +    │
│                       structured reason; record stays editable by the │
│                       submitter and re-submits to pending_approval     │
│                       (resubmission counter kept)                     │
│  All three write approval_requests.decided_by/decided_at/             │
│  decision_reason AND an audit_logs entry — the audit trail is already │
│  built.                                                               │
└───────────────────────────────────────────────────────────────────────┘
                              ↓
┌─ 4. Admission Confirmed ─────────────────────────────────────────────┐
│  Student is active + holds an enrollment → Fees, Exams, Library,      │
│  Hostel, Transport, Academics, ID Cards, Certificates, Reports all    │
│  resolve it exactly as they do today. No new integration.             │
└───────────────────────────────────────────────────────────────────────┘
```

**No separate enrollment approval stage** — enrollment is a side effect of the single admission-approval decision, which matches both the client's requirement and the current codebase (no enrollment approval exists today).

### 5.3 Status model — keeping the two statuses clearly separated

| Field | Meaning | Values |
|---|---|---|
| `students.registration_status` **(new, additive)** | *Where the registration is in the approval pipeline* | `pending_approval`, `correction_requested`, `approved`, `rejected` — **NULL = legacy/pre-existing student, treated as approved** |
| `students.status` (existing) | *Lifecycle of the person as a student* | `active`, `inactive`, `graduated`, `suspended`, `withdrawn` — unchanged |
| `student_enrollments.status` (existing) | *Lifecycle of one year's enrollment* | `active`, `completed`, `cancelled`, `withdrawn` — unchanged |
| `approval_requests.status` (existing) | *The decision record* | `pending`, `approved`, `rejected`, `cancelled` (+ one new `changes_requested`) |

Registration status answers "may this person be admitted?"; student/enrollment status answers "what is this person's standing?". They never overwrite each other.

### 5.4 Alternative considered and why it is not recommended

**Option C — keep `admission_applications` as the registration record.** One screen writes applicant + application + documents atomically; approval flips the application; approval auto-runs the existing `ConvertAdmissionToStudent` + `AdmissionService`.

- *Pro:* zero changes to `students`; every admission screen, report and test survives untouched.
- *Con:* **perpetuates the two-person-record problem** (§3.3.1) and the two-document-store problem (§3.3.2), and `admission_applicants` physically cannot hold guardian or Aadhaar data — so either the registration form stays incomplete, or ~25 columns get added to `admission_applicants`, duplicating `students` column-for-column. That is the exact duplication this project set out to remove.

Option A (recommended) adds **two nullable columns**; Option C adds **~25 duplicated columns or an incomplete form**.

### 5.5 Navigation after unification

One **Admission** group, all destinations reachable:

```
Admission
├── New Registration        → the unified form (students.create in registration mode)
├── Pending Approvals       → approvals/pending  (module=student filtered)
├── Registrations           → students.index?registration=pending|correction|rejected
├── Admitted Students       → students.index (the existing Student List)
├── Enrollments             → student-enrollments.index
├── Documents               → student-documents.index
├── ID Cards                → student-id-cards.index          (ONE entry, not two)
├── Transfers ▸ In / Out    → unchanged
├── Register (legacy) ▸     → Applications, Enquiries, Applicants, Merit Lists,
│                             Admissions, Admission Documents, Dashboard
│                             (read-only; retained for existing data & reports)
├── Settings ▸ Document Types · Approval Settings · ID Card Setting
└── Reports                 → admission-reports.index (unchanged, still valid)
```

The Students group keeps its 9 destinations. `SidebarStructureTest` and `AdmissionSidebarAndApprovalRegressionTest` must be updated in the same change — they currently assert the old structure verbatim.

---

## 6. Database & data-preservation considerations

### 6.1 Schema — one additive migration, no new table

```
students
  + registration_status        varchar(30)  NULL, index (college_id, registration_status)
  + registration_submitted_at  timestamp    NULL
```

Rules, following the precedent set by `2026_10_04_000001` and `2026_10_04_000002`:

- **Nullable, no default.** Every existing row keeps working; nothing is rewritten or backfilled.
- **NULL is semantically "legacy / already admitted"** — read as approved. No data migration, no backfill script, no risk to production rows.
- No existing column, index or constraint is modified or dropped.
- Not an enum/check constraint: the value set lives in a `Student::REGISTRATION_STATUSES` constant enforced at the validation boundary, matching the documented convention for `category`, `gender` and `guardian_relation`.
- `approval_requests.status` gains `changes_requested` as a **constant + vocabulary change only** — the column is already `varchar(20)`; `changes_requested` is 19 chars, so it fits with no DDL.

### 6.2 Tables that must NOT be touched

All nine admission tables stay. Specifically:

- `admission_document_types` — **hard FK dependency from `student_documents`**; dropping or renaming it breaks the Students module.
- `admission_applications` — target of `students.admission_application_id`; existing provenance links must survive.
- `admissions` — read by `AdmissionReportController`, `StudentReportService::admissions()`, `ConsolidatedReportService`, the admissions index and `Admission::student()`.
- `admission_applicants`, `admission_enquiries`, `admission_documents`, `admission_merit_lists`, `admission_merit_entries`, `admission_document_type_program` — hold live historical data and are covered by 33 passing tests.

They become **legacy / read-only + auto-written**, never deleted.

### 6.3 Data-preservation rules for the implementation

1. **No destructive migration.** Additive columns only; `down()` drops exactly what `up()` added.
2. **No re-write of history.** Existing students, applications, admissions, documents and enrollment rows are untouched. In-flight applications continue to work on the legacy screens until an explicit, separately-approved migration decision is made.
3. **Cancellation is a status, never a delete.** Preserve `AdmissionService::cancelAdmission`'s guard, which refuses to cancel an admission while a live student exists.
4. **Soft deletes everywhere.** A rejected registration is soft-deletable but never hard-deleted; its `approval_requests` row and `audit_logs` entries survive.
5. **No new permissions are created** (decision 1). Approval, rejection and correction-request all reuse the existing `approvals.approve`; resubmission reuses `students.update`. `DatabaseSeeder` is untouched. *If* a permission is ever added later, it must follow the established pattern — `Permission::firstOrCreate` in the seeder **plus** an in-migration backfill attaching with `syncWithoutDetaching`, exactly as `2026_10_17_000001` does. Never `sync()` (it detaches).
6. **`ApprovalRegistry` changes are one addition and one removal.** Its docblock promises *"Disabling approval does not silently invalidate existing pending requests; they remain pending and decidable."* The addition (`student.registration_approval`) must not disturb the four surviving actions. The removal (`admission.admission_create`, §8.3) is verified safe — no caller, no test, so no pending request can exist to orphan.
7. **Lock ordering is fixed.** Any approval-triggered creation must reuse `AdmissionConversionLock`'s documented order — **Application → College → Student/Enrollment** — and `StudentService::createEnrollment`'s tuple guard. Do not invent a second ordering.
8. **Numbering: student number is generated at registration, gaps accepted** (§8.3). `student_number` is generated inside `createStudent`'s transaction, so a rejected registration consumes a number and leaves a gap in `STU-YYYY-####`. This is accepted: the generator already has a `uniqid()` collision fallback, so gaps are normal, and every pending record gets a stable reference the approver can quote. No change to `GenerateStudentNumber`.
9. **No new unique DB constraint on the enrollment tuple.** `2026_09_18_000002` explicitly forbids it without the documented duplicate preflight on the production engine. The application-level guard in `StudentService::hasNonDeletedEnrollmentForTuple` remains the enforcement point.
10. **Concurrency caveat is unchanged and documented**: `AdmissionConversionLock` states that SQLite test defaults do not prove `SELECT … FOR UPDATE` behaviour, so production-engine verification stays outstanding. Do not claim stronger guarantees than the tests provide.
11. **Aadhaar handling must not regress.** `aadhaar_number` encrypted, `aadhaar_last4` for display, `aadhaar_hash` HMAC for duplicate detection, and `StudentService::AUDITED` deliberately excludes all three from the audit log. The unified form inherits this — do not audit the plaintext.
12. **No migration is to be run in this engagement** until the plan is approved.

---

## 7. Implementation plan (short)

| Phase | Work | Touches | Est. |
|---|---|---|---|
| **0** | ~~Client decisions~~ **DONE** — four decisions locked (§8), four defaults adopted (§8.3). One item flagged for confirmation: the three-row register projection of §8.2 | — | — |
| **1** | Additive migration (`registration_status`, `registration_submitted_at` — nullable, no default, no backfill); `Student::REGISTRATION_STATUSES`; `ApprovalRequest::STATUS_CHANGES_REQUESTED` (constant only, fits `varchar(20)`); `ApprovalRegistry`: **add** `student.registration_approval`, **remove** the dead `admission.admission_create`; policy ability for the correction decision. **No new permissions** (decision 1) — `DatabaseSeeder` untouched | 1 migration, 2 models, registry, 1 policy | S |
| **2** | **Unified registration form.** New `StudentRegistrationService` owning *validate → create pending student → store documents → raise approval request → notify*, with **actor and college as parameters** (§8.1). `students.create` gains a registration mode: documents section driven by `admission_document_type_program` for the chosen program, required types shown as an **advisory** checklist (§8.3). Writes Student (`registration_status='pending_approval'`, `status='inactive'`, **no enrollment**) + `student_documents` + one `ApprovalRequest` in a single transaction. Duplicate guards reused: phone-unique per college, Aadhaar HMAC, plus a name+DOB advisory | new `StudentRegistrationService`, `StudentController`, `StoreStudentRequest`, `students/_form.blade.php`, `StudentDocumentService` | M |
| **3** | **Decision wiring.** Extend `ApprovalService::applyApproval` and add `requestChanges()`, all gated by the existing `approvals.approve`:<br>• **Approve** → `registration_status='approved'`, `status='active'`, create the first enrollment via the existing `StudentService::createEnrollment` (tuple guard, college lock, section/year/program validation), then write the register projection of §8.2 (applicant → application → admission via the existing generators and `AdmissionService`, and set `students.admission_application_id`)<br>• **Reject** → `registration_status='rejected'`, stays `inactive`, never enrolled<br>• **Request Corrections** → `changes_requested` + `registration_status='correction_requested'` + structured reason; record stays editable under `students.update` and resubmits to `pending_approval` with a counter<br>Then wire the no-op `notifyApprovers()` to the existing `CommunicationNotificationService` | `ApprovalService`, `ApprovalController`, `approvals/show.blade.php`, `StudentRegistrationService`, `AdmissionService` | M |
| **4** | **Fix the two defects (§3.5).** Gate `AdmissionService::createFromApplication` on `approved`/`admitted` only — the §8.2 projection creates the application already `admitted`, so it passes the tightened gate. Remove the dead `admission.admission_create` toggle (done in Phase 1) so Approval Settings stops advertising a no-op | `AdmissionService` | S |
| **5** | **Navigation consolidation** (§5.5): one Admission group; legacy screens (Applicants, Enquiries, Applications, Documents, Merit, Admissions, Dashboard) under a collapsible **"Register (legacy)"**; single ID-card entry; projection rows visually flagged. Update `SidebarStructureTest` + `AdmissionSidebarAndApprovalRegressionTest` **in the same commit** — they hard-assert the current structure | `sidebar.blade.php`, 2 test files | S |
| **6** | **De-duplication & reports.** Re-point `student-reports?report=admissions` and `admission-reports` at one source; add `registration_status` guards to the four Student pickers of §4.3 (`FeeFormOptions`, `InventoryFormOptions`, `FinanceReportController@264`, `HostelReportController@232`) so pending registrations never appear in fee/hostel/inventory pickers; retire `ConvertApplicationToStudent`'s bare-POST route from the UI (keep the class — legacy register and tests use it) | 2 report controllers/services, 4 option providers, routes | S |
| **7** | **Tests.** New feature tests for registration → approval → enrollment (approve / reject / corrections / resubmit / duplicate / tenancy / self-approval / no-enrollment-means-invisible-to-fees / register projection written). **All 33 admission + 34 student tests must stay green** — they are the regression net for data preservation | `tests/Feature/Students`, `tests/Feature/Approval` | M |

Ordering rationale: schema and vocabulary first (Phase 1) so every later phase compiles against real constants; the two correctness defects (Phase 4) are isolated and independently valuable; navigation (Phase 5) comes after the flow works so the menu never points at a half-built screen.

---

## 8. Decisions locked (client-confirmed 2026-10-25)

| # | Decision | Answer | Consequence for the plan |
|---|---|---|---|
| 1 | Who is the "authorized administrator"? | **Keep the current rule** — any user holding `approvals.approve` in the college | **No new permission is created.** Phase 1 drops `students.registration.approve` / `.request_corrections`. Approve, Reject and Request-Corrections are all gated by the existing `approvals.approve`; resubmission is gated by the existing `students.update`. `DatabaseSeeder` needs no permission additions at all |
| 2 | Staff-entered or public self-service? | **Staff now, public later** — built so a public entry point needs no rework | The write path must live in a **service, not the controller**; the actor and college are **passed in**, never read from `auth()` / `TenantContext` inside the service; the form is a **plain POST with no session-dependent state**; duplicate and document checks are **server-side** so they cannot be bypassed by a future unauthenticated caller. See §8.1 |
| 3 | Fate of the `admissions` register | **Auto-write it on approval** | `admission-reports`, the admission register, `ConsolidatedReportService`, `Admission::student()` and all 33 admission tests keep working unchanged. **But see §8.2 — this necessarily writes three rows, not one** |
| 4 | Existing in-flight applications | **Run to completion** on the legacy screens | **No data migration.** Legacy screens stay reachable under "Register (legacy)". `registration_status` stays NULL for every pre-existing student and is read as "legacy / already admitted" |

### 8.1 Seams that make "public later" a no-rework addition

Decided now so Phase 2 does not have to be undone later:

1. **One write path, in a service.** A `StudentRegistrationService` (new class in `app/Domain/Student/Services/`) owns *validate → create pending student → store documents → raise approval request → notify*. `StudentController@store` becomes a thin caller. A future `PublicRegistrationController` calls the **same** service.
2. **Actor and college are parameters, not globals.** Signature takes `int $collegeId` and `?int $actorId`. The existing `StudentService::createStudent()` already follows this shape; the new service must not regress it by reaching for `auth()->id()` internally.
3. **No session or CSRF coupling in the service.** Session flashing stays in the controller.
4. **Server-side enforcement of everything the form shows.** Required-document checklist, duplicate guards and the status vocabulary are validated in the service/FormRequest — never only in Blade or JS — so an unauthenticated caller cannot skip them.
5. **Tenant resolution stays at the edge.** `TenantContext` is read in the controller/middleware and passed down. A public route (which has no tenant middleware) can resolve the college from the form's college selector instead, with no service change.
6. **Rate limiting is a route concern.** A future public route adds `throttle:` exactly as `login.store` already does — no service change.

### 8.2 ⚠ Consequence of decision 3 that must be accepted explicitly

**An `admissions` row cannot exist on its own.** Verified in the schema:

- `admissions.application_id` → `admission_applications`, **NOT NULL** (`2026_09_16_000011`, line 15)
- `admissions.applicant_id` → `admission_applicants`, **NOT NULL** (line 16)
- `admission_applications.applicant_id` → `admission_applicants`, **NOT NULL** (`2026_09_16_000004`, line 13)

So auto-writing the register on approval writes **three rows**, in this order, inside the approval transaction:

```
1. admission_applicants   snapshot of the student's identity (name, email, phone×2, gender, DOB, address_line_1)
2. admission_applications status 'admitted', academic_year_id + program_id from the enrollment,
                          application_number from the existing GenerateApplicationNumber
3. admissions             admission_number from the existing GenerateAdmissionNumber, via AdmissionService
4. students.admission_application_id ← row 2   (preserves the provenance link and makes Admission::student() resolve)
```

**This is a deliberate, one-directional projection — not a second front door.** `students` remains the single source of truth; the applicant/application/admission rows are a derived *read model* that keeps the existing register and reports alive. They are machine-written, never hand-keyed, and never edited back into `students`. The duplicate-person-record problem of §3.3.1 is thereby **contained rather than eliminated**: eliminated as a *data-entry* burden and as a *divergence* risk (nothing writes to it except this one projection), retained as storage.

Costs to accept: one extra `admission_applicants` row per admitted student; `enquiry_number` is never generated (no enquiry in the new flow); the legacy Applicants/Enquiries screens will show these projection rows alongside genuine historical ones — they should be flagged as legacy/read-only in Phase 5.

If that cost is unacceptable, the alternative is decision 3(b) "stop writing it", which requires re-pointing `AdmissionReportController`, `StudentReportService::admissions()` and `ConsolidatedReportService` at `students` and rewriting the corresponding tests. **Flagged for confirmation; the plan below proceeds on 3(a) as chosen.**

### 8.3 Still open (non-blocking — defaulted, can be revised)

| Question | Default adopted in the plan |
|---|---|
| Student number at registration or at approval? (§6.3 rule 8) | **At registration** — accept gaps in `STU-YYYY-####`. The generator already has a `uniqid()` collision fallback, so gaps are normal. Gives every pending record a stable reference for the approver |
| Admission documents on approval | **Not copied.** The new flow uploads straight into `student_documents`, so there is nothing to carry over. `admission_documents` keeps only legacy rows. Avoids duplicating files on the private disk |
| Enquiries and merit lists | **Read-only under "Register (legacy)"** — retained, not deleted |
| Required documents: advisory or hard block? | **Advisory checklist first.** Per-program required types from `admission_document_type_program` are *shown* on the form and *listed for the approver*, but do not block submission. Promoting this to a hard block is a one-line validation change later |
| Dead `admission.admission_create` registry entry (§3.5.1) | **Remove it.** Verified safe: `grep -rn "admission_create" app tests resources database` returns only the registry declaration and the dead `applyApproval` branch — no caller, no test, so no pending request can ever have existed to orphan. Removing it stops Approval Settings advertising a toggle that does nothing |

---

## 9. Confirmation of restrictions observed

- ✅ **No code changed.** This document is the only file added; `git status` shows it as untracked and `git diff --stat` on tracked files is empty. No `.php`, `.blade.php`, route, migration, seeder, config or test was modified.
- ✅ **Nothing deleted.** All 9 admission tables, 8 models, 11 controllers, 37 views and 33 tests are intact and untouched.
- ✅ **No new tables proposed** — one additive two-column migration on the existing `students` table (§6.1).
- ✅ **No separate enrollment workflow introduced** — enrollment remains a side effect of the single admission-approval decision, matching both the requirement and the current codebase.
- ✅ **No unrelated module modified.** Fees, Exams, Library, Hostel, Transport, HR, Inventory, Communication, Certificates and Academics were only *read* in this audit.
- ✅ **Nothing pushed, merged, deployed or migrated.** No `git push`, no `gh pr`, no `artisan migrate`, no `db:seed`.

---

## 10. Status

**Audit complete.** Four client decisions are locked (§8) and four non-blocking defaults are adopted (§8.3). The design is frozen and the seven-phase plan in §7 is ready to execute.

**One item is flagged for explicit confirmation before Phase 3 begins** — §8.2: because `admissions.application_id`, `admissions.applicant_id` and `admission_applications.applicant_id` are all NOT NULL, the chosen "auto-write the register" option necessarily writes **three derived rows** (applicant → application → admission) per admission, as a one-directional projection from `students`. This contains the duplicate-person-record problem rather than eliminating it. The alternative is §8's decision 3(b), which requires re-pointing three report services and rewriting their tests.

**No implementation will begin until that confirmation and the go-ahead are received.**

---

## Appendix A — Phase 1 / Phase 4 read-only risk analysis (verified by source inspection)

Performed before any implementation. Every claim below is checkable by reading the named files.

### A.1 Gating `AdmissionService::createFromApplication` on `approved`/`admitted` is **safe** w.r.t. the existing suite

Today the service blocks only `draft`, `rejected`, `cancelled` (`AdmissionService.php:69`), so a `submitted` or `under_review` application can be confirmed as an admission. Tightening this to `approved`/`admitted` was checked against every test that can reach `admissions.store`:

| Test / fixture | Application status used | Reaches `admissions.store`? | Affected by the gate? |
|---|---|---|---|
| `AdmissionManagementTest::makeApplication()` (line 49) | default **`approved`** | yes (lines 71, 103, 108, 124, 139, 241, 295) | **No** — `approved` passes |
| `PhaseDAdmissionFixtures::phaseDAdmission()` (line 86) | **`admitted`** | builds the row directly | **No** |
| `PhaseDAdmissionFixtures::phaseDApplication()` (line 68) | default `under_review` | used by `AdmissionApplicantIntegrityTest`, `AdmissionApplicationBulkActionsTest`, `AdmissionCancelGuardTest`, `AdmissionCancellationConversionTest`, `AdmissionConversionLockTest`, `AdmissionEnquiryBulkActionsTest` — **none POST to `admissions.store`** | **No** |
| `AdmissionFoundationTest` (line 310) | `submitted` | creates rows directly, then only counts them | **No** |
| `AdmissionWorkflowTest` | `submitted` | asserts `AdmissionApplicationWorkflow::canTransition`, never the admission service | **No** |

**No test anywhere asserts that a `submitted` or `under_review` application can become an `admissions` row.** The only occurrence of the current permissive wording is the error string itself (`AdmissionService.php:71`), which no test greps for. The gate can therefore be tightened without editing an existing test.

### A.2 Removing the dead `admission.admission_create` registry entry is **safe**

`grep -rn "admission_create" app tests resources database` returns exactly two hits: the declaration in `ApprovalRegistry.php:31` and the no-op branch in `ApprovalService.php:260`. No caller, no test, no view literal.

Registry-enumerating code that could be affected:
- `ApprovalController@settings` (line 118) renders `ApprovalRegistry::allSupported()` — it simply renders one fewer row.
- `ApprovalManagementTest` (lines 298–301) asserts only that `approvals.settings.index` returns 200 for a permitted user and 403 for an unpermitted one. It does **not** assert the number of rows or the presence of any specific action.
- `AdmissionSidebarAndApprovalRegressionTest` (line 24) asserts `approvals.settings.index` is 200.

No test enumerates or counts registry actions, so removal is behaviour-preserving for the suite.

### A.3 `approval_requests.status` can hold `changes_requested` with **no DDL**

The column is `string('status', 20)` (`2026_10_17_000001`). `changes_requested` is 19 characters. Adding it is a constant + vocabulary change in `ApprovalRequest`; no migration is required for it.

### A.4 `students` currently has **no** status-like column that would collide

`Student::STATUSES = active, inactive, graduated, suspended, withdrawn`. There is no `registration_status`, and no migration adds one. The two additive columns of §6.1 are genuinely new names — `grep -rn "registration_status\|registration_submitted_at"` across every `.php` and `.blade.php` file returns zero hits, and all 114 migrations were checked.

### A.5 No existing test asserts a fixed column list for `students`

`StudentService::AUDITED` (an explicit column allowlist) is the only place that enumerates student columns, and it is used for audit payloads, not for schema assertions. Adding two nullable columns does not change it — but Phase 1 must decide explicitly whether the new columns are audited (recommendation: **yes** for `registration_status`, **no** for `registration_submitted_at`, which is a mechanical timestamp like `submitted_at` on applications).
