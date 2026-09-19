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
- Duplicate **active** enrollment prevention (same student/year/program) is done
  transactionally in `StudentService::createEnrollment` (student-row lock +
  conflict check), deliberately **not** via a hard composite unique — a hard
  unique would occupy its key after a soft delete and block legitimate
  re-enrollment. Once the production DB engine is fixed, this becomes a partial
  unique index (`WHERE deleted_at IS NULL`) per `docs/architecture.md`.

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
