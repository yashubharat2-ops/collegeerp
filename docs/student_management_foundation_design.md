# Student Management — Foundation Design

Phase 1 of the Student Management module. This phase delivers a clean, scalable
foundation (domain, models, tenancy, RBAC, conversion, UI, tests) that future
modules build upon. Attendance, Fees, Examination, Hostel, Transport, Alumni,
and the Student portal are intentionally **not** part of this phase.

---

## 1. Student vs AdmissionApplicant lifecycle

`AdmissionApplicant` and `Student` are **different lifecycle entities** and are
not merged or renamed.

| Entity | Lifecycle stage | Meaning |
| --- | --- | --- |
| `AdmissionApplicant` | Enquiry → application | Canonical *person* record during admission. Single source of truth for prospect/application personal data. |
| `Admission` | Admission confirmation | Final admission record derived from an approved application (existing module). |
| `Student` | Enrollment onward | The officially enrolled person. Created when real enrollment begins. |
| `StudentEnrollment` | Per academic year | One periodic enrollment record; composes the student's historical academic record. |

So the lifecycle chain is:

```
AdmissionApplicant
        ↓ (applies)
AdmissionApplication        (optional: Admission)
        ↓ (approved/admitted → converted)
Student
        ↓ (one per academic year)
StudentEnrollment
```

---

## 2. AdmissionApplication → Student relationship

`students.admission_application_id` is a **nullable provenance link** back to
the application a student was converted from.

- It is populated **only** by `ConvertApplicationToStudent` — the manual
  `StoreStudentRequest` strips the field, so an application can never be linked
  to a student outside the status-checked, transactional conversion workflow.
- It carries an index, not a hard unique: conversion idempotency is enforced in
  the action (application-row lock + live-student lookup) so a soft-deleted
  student never blocks a legitimate re-creation (mirroring the admission number
  generator's college-row-lock approach).

---

## 3. StudentEnrollment purpose

A student is **not** tied permanently to one academic year/program. Every
periodic enrollment is a separate row with its own:

- academic-year (required, tenant-scoped),
- program (nullable, tenant-scoped),
- server-generated `enrollment_number`,
- `enrollment_date`, `status`, `remarks`,
- soft deletes for historical retention.

Moving a student to a new academic year simply creates a new row; the old
enrollment is cancelled/soft-deleted (its row and number are preserved), never
overwritten or destroyed.

---

## 4. Student numbering strategy

Student numbers are:

- **server-generated** by `app/Domain/Student/Actions/GenerateStudentNumber.php`,
- **tenant-aware** (sequential per college, unique within the college),
- **collision-safe** (the college row is `lockForUpdate()`-serialised, then a
  uniqueness check loop with an `uniqid()` fallback runs inside the transaction — the same scheme as the admission/applicant/enquiry number generators),
- **never trusted from the client** (`student_number` is stripped in Form Requests).

Format today is `STU-{YEAR}-{0001}` (e.g. `STU-2026-0001`), with YEAR from the
academic-year code when known, else the current calendar year. The format is a
**parameterisable convention, not an institution rule**: the table stores the
full string, so future formats (college code, initials, different separators)
only change the generator, never the schema or model.

Enrollments use the parallel `GenerateEnrollmentNumber` (`ENR-{YEAR}-{0001}`).

---

## 5. Tenant isolation

Students and StudentEnrollments both use `BelongsToCollege` + `CollegeScope`.

- Reads are globally scoped to `TenantContext`; a foreign-college row is simply
  "not found" (404, not 403) — no tenant leak.
- Writes derive `college_id` from the server-side tenant context; the browser's
  `college_id` is stripped in every Form Request.
- FK validation uses `Rule::exists(...)->where('college_id', $collegeId)` for
  every tenant-owned reference (`academic_years`, `programs`, `students`,
  `admission_applications`), so another college's records can never be attached.
- The conversion action re-resolves the application **and** the academic
  year/program tenant-scoped, and the enrollment service locks and re-resolves
  the student tenant-scoped.

Tests assert that College A cannot view/edit/delete College B students or attach
College B's academic years/programs.

---

## 6. Authorization model (RBAC)

Standard policies + permission slugs, following the existing architecture:

- `students.view` / `students.create` / `students.update` / `students.delete`
- `student_enrollments.view` / `student_enrollments.create` /
  `student_enrollments.update` / `student_enrollments.delete`

Policies (`StudentPolicy`, `StudentEnrollmentPolicy`) check `User::hasPermission()`
with the model's `college_id` for instance actions. There is no Super Admin
bypass, no hard-coded user IDs, and controllers never branch on roles. The
conversion route requires `students.create`.

The new slugs are seeded in `DatabaseSeeder` for the demo super-admin and
college-admin roles, exactly like the Admission module.

---

## 7. Historical enrollment strategy

- Students accumulate `StudentEnrollment` rows across academic years.
- `created_by`/`updated_by` + timestamps + soft deletes preserve the audit/history trail.
- Exactly one non-soft-deleted enrollment may occupy a
  `(college_id, student_id, academic_year_id, program_id)` tuple, across **all**
  statuses. `section_id` is not part of the tuple. A `NULL` `program_id` is one
  shared group for that student/year/college, not a way to create multiple
  program-less enrollments.
- `StudentService::createEnrollment()` is the central transactional create
  path for the form, imports, conversions and promotions. It locks College
  before Student, re-resolves tenant-owned references and applies the same
  tuple predicate everywhere. Soft-deleted rows remain as history and do not
  block a later enrollment.
- Ordinary single-record and bulk status changes cannot move a `completed`,
  `cancelled` or `withdrawn` enrollment back to `active`. Promotion completion
  and TC withdrawal remain supported internal transitions; no reactivation UI
  or permission was added.
- No database uniqueness migration or migration run is part of this work. The
  production database engine/version is not confirmed, and no historical rows
  are modified. Before considering any future index, run this **read-only**
  preflight and review every returned tuple:

  ```sql
  SELECT college_id, student_id, academic_year_id, program_id, COUNT(*) AS live_enrollments
  FROM student_enrollments
  WHERE deleted_at IS NULL
  GROUP BY college_id, student_id, academic_year_id, program_id
  HAVING COUNT(*) > 1
  ORDER BY college_id, student_id, academic_year_id, program_id;
  ```

  `GROUP BY` includes the `program_id IS NULL` group, and statuses are
  intentionally not separated because every non-deleted status occupies the
  same tuple. Do not delete or rewrite historical records to make a future
  constraint fit.

### Lifecycle lock order and operational notes

- Direct enrollment creation takes **College → Student → Enrollment**; the
  enrollment number is computed while the College lock is already held.
  Student creation now generates its number inside the write transaction,
  before inserting Student / optional first Enrollment, so there is no
  Student → College inversion.
- Promotion approval uses **College → Promotion → Student → source Enrollment**
  and revalidates Student/source statuses only after those rows are locked.
  The target enrollment goes through `StudentService` while the College lock
  remains held.
- Standalone TC issuance uses **College → Transfer → Student → all live
  Enrollments ordered by id**. It proceeds only when there are no active
  enrollments or the sole active enrollment is linked to that transfer. TC
  numbering uses the already-held College lock.
- Certificate issuance retains its existing
  **College → Certificate → Type → Transfer** sequence. Its nested TC service
  call is passed the already-held College lock and follows Transfer → Student →
  Enrollment, so it introduces no Certificate/TC inversion.
- Admission creation and both admission-to-student conversion routes acquire
  the Application row first, then College, before locking or inserting an
  Admission, Student or Enrollment row. The admission-based conversion performs
  only an unlocked, tenant-filtered read before its transaction to discover the
  linked application ID; it re-locks and verifies the Admission after acquiring
  Application → College. Admission cancellation takes Application, checks for
  a linked Student and then updates Admission; it never acquires College. In the
  traced admission create/convert/cancel paths, no workflow holds College and
  then waits for an Application or Admission lock.
- `StudentImportService` is intentionally all-or-nothing and wraps the file in
  one transaction. Its first number-generation lock on College therefore stays
  held through that import's commit, which can delay other number-generating
  writes in the same college on large files. This preserves existing atomic
  import semantics; no counter table or per-row partial-commit behavior was
  introduced.
- The production database engine, version and isolation level are not confirmed.
  Number generators choose a max sequence with ordinary `pluck` reads and probe
  candidates with ordinary `exists` reads after locking College. Snapshot
  behavior is engine/isolation/transaction-context dependent: PostgreSQL
  `REPEATABLE READ` pins a transaction snapshot at its first statement (which
  may be a `SELECT ... FOR UPDATE`), while InnoDB uses current reads for locking
  statements but its ordinary reads can reuse a read view established earlier
  in an enclosing transaction. A wait for College therefore does not portably
  guarantee a fresh later scan. Existing per-college number unique indexes
  remain the final duplicate barrier; a stale scan can still make the write
  fail instead of selecting the next number. Verify the production engine,
  version, isolation and transaction nesting, then run a concurrent,
  multi-connection test where one number-generation transaction waits for
  another to commit before scanning. No engine-specific current-read behavior
  is assumed. SQLite test defaults do not establish production `FOR UPDATE`
  behavior.

---

## 8. Admission → Student conversion

`app/Domain/Student/Actions/ConvertApplicationToStudent.php` converts an
approved/admitted `AdmissionApplication` into a `Student` (+ initial
`StudentEnrollment`). It is:

- **transactional** (wraps student + enrollment creation in one transaction),
- **idempotent** (the application row is locked; an existing live student for
  that application is returned instead of a duplicate),
- **state-checked** (`draft` / `rejected` / `cancelled` / `submitted-without-approval`
  applications are refused — admission workflow authorization is never bypassed),
- **tenant-verified** (application, academic year, and program are re-resolved
  within the tenant),
- **server-numbered** (student + enrollment numbers via the locking generators),
- **audited** with a single `student.converted` entry (person-snapshot copied,
  no sensitive data exposed).

The admission application workflow (`AdmissionApplicationWorkflow`) is now a
strict lifecycle: `draft → submitted → under_review → approved → admitted`,
with `cancelled`/`rejected` terminal-ish branches. Invalid jumps such as
`draft → approved` or `rejected → approved` are rejected, so conversion can
only originate from a legitimately approved/admitted application.

---

## 9. Future extension points

> Delivered since this document: the Student Create/Edit form added the profile
> sections (parent/guardian, identity & government IDs, contact, academic
> snapshot, additional information, photo, documents link) as nullable columns on
> `students`, with Aadhaar encrypted + masked and no new entity. See
> [`docs/student_create_edit_form.md`](student_create_edit_form.md). A
> multi-guardian table remains the future extension point described below.


- **Profile**: `Student` already snapshots identity/contact/address; a future
  profile module adds non-identity preferences, emergency contact, etc.
- **Guardian/parent**: a `guardians` table keyed to `student_id` (tenant-scoped).
- **Documents / ID card**: `student_documents` and card issuance reference
  `student_id`; `photo_path` is already reserved on `students`.
- **Attendance / Examination / Fees / Hostel / Transport**: all hang off
  `StudentEnrollment` (the year-scoped record) rather than `Student`, so a
  student's per-year data stays correctly isolated.
- **Communication**: reference `student` / `enrollment` with the existing audit
  + `created_by` conventions.
- **Alumni**: derives from terminal `Student.status` (`graduated`) and the
  historical enrollment trail.

---

## 10. Important design decisions

1. **Person snapshot, not live join.** Student person data is copied at
   conversion time from `AdmissionApplicant`; it does not `belongsTo` the
   applicant for its identity fields. This keeps the enrolled record stable if
   the applicant is later corrected/soft-deleted, while `applicant_id` remains
   authoritative for the admission side.
2. **Numbers are format-agnostic strings.** No enum/schema assumption about the
   prefix or width; only the generator knows the shape.
3. **Deterministic pagination.** Every list (Students, Enrollments, and the
   corrected Admission lists) orders `created_at ASC, id ASC` so rows never
   shuffle between pages under equal-second timestamps — fixing the class of
   pagination bug the Admission module had.
4. **Conversion is the only place `admission_application_id` is set.** This
   keeps the provenance invariant airtight.
5. **No speculative modules.** No Attendance/Fees/Examination/Hostel/Transport/
   Alumni/portal tables or routes were created.

---

## 11. Lifecycle rules as implemented (registration → enrollment)

The stages, and the single place each one is decided. Nothing here is a second
mechanism: every step reuses the module it belongs to (the registration wizard,
Approval Management, `StudentService`, the Admission module).

| Stage | Who / where | Writes | Identifiers |
| --- | --- | --- | --- |
| Registration draft | `StudentRegistrationWizardController` | `student_registration_drafts` only | none |
| Submission | `StudentRegistrationService::register()` | one `students` row (`registration_status = pending_approval`, `status = inactive`) + one `approvals` request | `student_number` via `GenerateStudentNumber`, year code from the draft's academic year |
| Registration approval | `ApprovalService::approveStudentRegistration()` | student → `approved` / `active`; the **one** initial enrollment | reuses the already-issued `student_number`; the enrollment gets `enrollment_number` (+ roll number when a section is attached) |
| Admission (applicant route) | `AdmissionService::createFromApplication()` | `admissions` row | `admission_number` at confirmation only, for students converted from an application |
| Enrollment (Enrollments screen) | `EnrollmentApprovalService::create()` → `StudentService::createEnrollment()` | `student_enrollments` row (+ `approvals` request when gated) | `enrollment_number` server-side; roll number from `GenerateRollNumber` |
| Enrollment approval | `ApprovalService::approveStudentEnrollment()` | enrollment → `active` (or `rejected`) | none — approval never re-numbers a record |

1. **Registration approval is the confirmed admission** for a direct
   registration. The separate Admission workflow remains the route for
   applicant-originated admissions (`AdmissionService::ADMITTABLE_STATUSES`);
   registration approval does not create an `admissions` row and therefore no
   Admission Number. `admission_number` is minted only when an admission is
   confirmed, and is displayed next to a student when they were converted from
   an application.
2. **A saved enrollment is not automatically an approved one.** When a college
   enables *Approval Management → Students → Student Enrollment*, a new
   enrollment is stored as `pending_approval` with its numbers already issued,
   and becomes `active` only through a decision by a user holding
   `student_enrollments.approve`. The gate defaults to **off**, like every
   module except admissions and student registration, so a college that never
   switches it on keeps the historical single-step behaviour.
3. **Workflow statuses are not form choices.** `pending_approval` and
   `rejected` are absent from the create/edit/bulk status pickers and are
   rejected by the Form Requests and the bulk handler; the status of a pending
   enrollment cannot be changed by the ordinary update form at all.
4. **Enrollment eligibility.** Only an approved registration (or a legacy
   student with `registration_status = NULL`) may hold an enrollment; the
   picker and `StudentService::createEnrollment()` enforce the same rule.
5. **Student List visibility** is about the *person*, not their enrollments:
   approved registrations and legacy students are listed, pending/rejected ones
   are reachable through the explicit `registration_status` filter. An approved
   student with no enrollment is therefore still visible.
6. **Sections.** No programme declares a section requirement (there is no such
   column), so a section is optional at enrollment; when one is attached, the
   roll number is allocated within that academic year + programme + section.
   Making a section mandatory per programme would be a new, additive column and
   an explicit business decision.
7. **Numbers are never re-issued or rewritten.** The Student ID is stable
   across approval, re-enrollment, promotion and transfers; each enrollment has
   its own number; a rejected enrollment keeps its numbers as history.
