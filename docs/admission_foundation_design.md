# Admission Module — Foundation Design (Phase 1)

**Date:** 2026-09-16
**Status:** Foundation / Architecture — not full workflow
**Branch:** arena/01a0a8d1-collegeerp (task branch)
**Context:** Multi-college, tenant-aware Laravel 12 ERP. Existing modules: College, Campus, AcademicYear, Department, Program, RBAC, Audit, TenantContext.

---

## 1. Goals

- Establish tenant-safe admission domain that can support the full roadmap:
  1. Enquiry
  2. Applicant
  3. Application
  4. Document Verification
  5. Merit / Selection
  6. Admission
  7. Admission Fee integration

- Avoid duplicate source-of-truth for person data across Enquiry / Applicant / Application.
- Preserve historical records (soft deletes, no hard overwrites).
- Allow AcademicYear and Program association without assuming Program belongs to single admission cycle.
- Enable future Student Management reuse.
- Follow existing conventions: BelongsToCollege, CollegeScope, softDeletes, explicit FKs, unique constraints per college, status fields, audit logging, FormRequest stripping college_id.

---

## 2. Existing Architecture Reused

- **TenantContext** (`App\Support\Tenancy\TenantContext`): request-scoped, explicit. Must be set via ResolveTenant middleware.
- **BelongsToCollege** trait (`App\Domain\Foundation\Traits\BelongsToCollege`): adds global CollegeScope and auto-fills college_id on creating from TenantContext.
- **CollegeScope** (`App\Domain\Foundation\Scopes\CollegeScope`): if no context, `1=0` (no rows), prevents leakage.
- **AcademicYear**: college_id, code unique per college, status, softDeletes, date CHECK constraint.
- **Program**: college_id, department_id nullable, code unique per college, status, softDeletes, indexes on college+name, college+status, college+department_id.
- **Department**: college_id, campus_id nullable, code unique per college, status, softDeletes.
- **RBAC**: Permission slugs `*.view`, `*.create`, etc., policies check `hasPermission(..., college_id)`.
- **Audit**: `AuditLogService::record()` append-only, old/new values, college_id from context.
- **FormRequest pattern**: `prepareForValidation()` removes `college_id`; rules use `Rule::exists(...)->where('college_id', $collegeId)` to prevent cross-college attachment.
- **Controller pattern**: `findScoped()` via `Model::query()->findOrFail()` (scoped query, not route-model binding), then `authorize()`.
- **Test helpers**: `DepartmentTestHelpers` trait with `makeCollege()`, `makeUserWithPermissions()`, `asCollege()` (actingAs + session active_college_id).
- **Migration conventions**: `foreignId()->constrained()->cascadeOnDelete()` or `nullOnDelete()`, explicit indexes, unique per college, `softDeletes()`, `timestamps()` UTC.

---

## 3. Core Entities — Foundation

### 3.1 AdmissionApplicant (person)

**Purpose:** Canonical person record for anyone who enquires / applies. Single source of truth for contact / personal data. Reusable by Student Management (Student will reference applicant_id, preserving history).

**Table:** `admission_applicants`

**Fields:**
- `id` PK
- `college_id` FK colleges.id cascadeOnDelete, NOT NULL, indexed
- `first_name` string 255 NOT NULL
- `middle_name` string 255 nullable
- `last_name` string 255 NOT NULL (kept required for consistency; empty string not allowed)
- `email` string 255 nullable, indexed (not unique — same email may appear for different persons, but indexed for search)
- `phone` string 30 nullable, indexed
- `alternate_phone` string 30 nullable
- `gender` string 20 nullable (values: male, female, other, prefer_not_to_say — validated in future FormRequest, not DB enum to avoid hard-coded institution rules)
- `date_of_birth` date nullable
- `address` text nullable
- `status` string 20 default 'active' indexed (active, inactive, blocked) — person-level status, not workflow
- `created_by` FK users.id nullable nullOnDelete
- `updated_by` FK users.id nullable nullOnDelete
- `created_at`, `updated_at` timestamps
- `deleted_at` softDeletes

**Unique / Indexes:**
- `unique` not on email/phone (allows duplicates, avoids false uniqueness across colleges)
- `index ['college_id', 'status']`
- `index ['college_id', 'email']`
- `index ['college_id', 'phone']`
- `index ['college_id', 'first_name', 'last_name']` for search
- `index ['college_id']` (implicit via FK)

**Relationships:**
- `college(): BelongsTo`
- `enquiries(): HasMany AdmissionEnquiry`
- `applications(): HasMany AdmissionApplication`
- Future: `documents()`, `student()` etc.

**Deletion / Retention:**
- Soft delete preserves history. Hard delete only via college cascade (college deletion is soft itself, so hard delete rare). Applications/enquiries cascade on hard delete (college removal), but soft delete of applicant does NOT cascade hard; future logic may soft delete related or block if active applications exist.

**Tenant boundary:**
- Uses `BelongsToCollege`, global scope ensures no cross-college leakage. `college_id` never from browser.

---

### 3.2 AdmissionEnquiry (prospect)

**Purpose:** Track prospective student/contact before formal application. Tracks interested program, academic year, source, status. Avoids duplicating person data by referencing applicant.

**Table:** `admission_enquiries`

**Fields:**
- `id` PK
- `college_id` FK colleges.id cascadeOnDelete NOT NULL
- `applicant_id` FK admission_applicants.id cascadeOnDelete NOT NULL — enforces single source of truth; enquiry cannot exist without person. If business needs enquiry without full applicant, create minimal applicant first (first_name, phone).
- `academic_year_id` FK academic_years.id nullable nullOnDelete — admission for which year; nullable to preserve enquiry if year soft/hard deleted, but future validation will require it.
- `program_id` FK programs.id nullable nullOnDelete — interested program
- `enquiry_number` string 50 NOT NULL — human-readable reference, unique per college
- `source` string 100 nullable — e.g., website, referral, walk_in, advertisement, social_media, other — free text, not enum, to avoid institution-specific hard-coding
- `status` string 30 default 'new' indexed — lifecycle: new, contacted, followed_up, converted, closed, dropped (future FormRequest validates allowed set)
- `remarks` text nullable
- `enquired_at` timestamp nullable — when enquiry was made (defaults to created_at if null)
- `next_follow_up_at` timestamp nullable
- `created_by`, `updated_by` FK users nullable nullOnDelete
- `created_at`, `updated_at`
- `deleted_at` softDeletes

**Unique / Indexes:**
- `unique ['college_id', 'enquiry_number']` — numbers unique per college, allows same number in other college
- `index ['college_id', 'academic_year_id']`
- `index ['college_id', 'program_id']`
- `index ['college_id', 'status']`
- `index ['college_id', 'applicant_id']`
- `index ['college_id', 'source']`

**Relationships:**
- `college()`, `applicant(): BelongsTo AdmissionApplicant`, `academicYear(): BelongsTo AcademicYear`, `program(): BelongsTo Program`, `applications(): HasMany AdmissionApplication` (via enquiry_id)
- Future: `followUps()`, etc.

**Lifecycle:**
- new → contacted → followed_up → converted (to application) or closed/dropped. Status field, not separate table, to keep simple. History preserved via audit logs and timestamps.

**Deletion / Retention:**
- Soft delete. Hard delete cascades from college or applicant hard delete.

**Avoiding duplication:**
- No first_name, email, phone in enquiry table; those live in applicant. Enquiry snapshot for history is preserved via applicant's current data + audit logs; if strict snapshot needed, future `enquiry_snapshots` or audit can store old_values. For foundation, we avoid duplication.

---

### 3.3 AdmissionApplication (formal application)

**Purpose:** Formal application by an applicant for a program/academic year. Supports multiple applications per applicant (applicant may apply to multiple programs/years). Preserves history, supports number/reference, status, submission lifecycle.

**Table:** `admission_applications`

**Fields:**
- `id` PK
- `college_id` FK colleges cascadeOnDelete NOT NULL
- `applicant_id` FK admission_applicants cascadeOnDelete NOT NULL
- `academic_year_id` FK academic_years nullable nullOnDelete
- `program_id` FK programs nullable nullOnDelete
- `enquiry_id` FK admission_enquiries nullable nullOnDelete — optional link to originating enquiry, preserves conversion chain
- `application_number` string 50 NOT NULL — unique per college
- `status` string 30 default 'draft' indexed — lifecycle: draft, submitted, under_review, verified, shortlisted, selected, rejected, admitted, withdrawn, cancelled
- `submitted_at` timestamp nullable — when formally submitted
- `remarks` text nullable
- `created_by`, `updated_by` FK users nullable nullOnDelete
- `created_at`, `updated_at`
- `deleted_at` softDeletes

**Unique / Indexes:**
- `unique ['college_id', 'application_number']`
- `index ['college_id', 'academic_year_id']`
- `index ['college_id', 'program_id']`
- `index ['college_id', 'status']`
- `index ['college_id', 'applicant_id']`
- `index ['college_id', 'enquiry_id']`
- `index ['applicant_id', 'academic_year_id']` — applicant may have multiple applications per year
- `index ['college_id', 'academic_year_id', 'program_id']` — common filter for merit/selection
- `index ['college_id', 'academic_year_id', 'status']`

**Relationships:**
- `college()`, `applicant()`, `academicYear()`, `program()`, `enquiry(): BelongsTo AdmissionEnquiry nullable`
- Future: `documents(): HasMany`, `meritRecords()`, `admission(): HasOne`

**Lifecycle:**
- draft → submitted (sets submitted_at) → under_review → verified → shortlisted/selected/rejected → admitted (conversion to student) or withdrawn/cancelled. Status transitions will be enforced in future Actions, not DB CHECK, to avoid institution-specific hard-coding. Preserve history via status field + audit logs.

**Deletion / Retention:**
- Soft delete preserves historical application. Hard delete only via college/applicant cascade. Application soft delete does not delete applicant.

---

## 4. Future Tables (NOT implemented in this foundation task)

- **admission_documents**: id, college_id, applicant_id nullable, application_id nullable, document_type, file_path, verification_status, verified_by, verified_at, remarks, created_by, etc. Supports multiple docs per applicant/application.
- **admission_merit_lists / admission_selections**: id, college_id, academic_year_id, program_id, application_id, applicant_id, merit_rank, score, category, status, remarks. Allows merit/selection without tight UI coupling.
- **admissions**: id, college_id, applicant_id, application_id, academic_year_id, program_id, admission_number unique per college, admitted_at, status, remarks. Conversion from successful application without losing history (application preserved).
- **students** (Student Management): id, college_id, applicant_id, admission_id, student_code, etc. References applicant for person reuse.

These future tables will all include college_id, academic_year_id, program_id where relevant, with appropriate indexes, softDeletes, and FKs to preserve history.

---

## 5. Tenant Boundaries & Security

- Every admission table has `college_id` NOT NULL, FK cascadeOnDelete, uses `BelongsToCollege` trait.
- `CollegeScope` ensures queries are scoped to active TenantContext; if no context, no rows.
- `college_id` never taken from browser: FormRequest `prepareForValidation()` will strip it (future), and trait auto-fills from TenantContext on creating.
- `academic_year_id`, `program_id`, `applicant_id`, `enquiry_id` must be validated with `Rule::exists(...)->where('college_id', $collegeId)` in future FormRequests, mirroring Department/Program.
- Policies will check `hasPermission('admission_enquiries.view', $collegeId)` etc., similar to DepartmentPolicy.
- Audit logging will record create/update/delete with college_id from context.

---

## 6. Unique Constraints & Indexes Summary

- **admission_applicants**: indexes on college+status, college+email, college+phone, college+name. No unique on email to allow flexibility.
- **admission_enquiries**: unique college+enquiry_number, indexes on college+academic_year, college+program, college+status, college+applicant, college+source.
- **admission_applications**: unique college+application_number, indexes on college+academic_year, college+program, college+status, college+applicant, college+enquiry, applicant+year, college+year+program, college+year+status.

These support common queries: listing enquiries/applications per college, filtering by academic year, program, status, applicant.

---

## 7. Deletion / Retention Strategy

- All admission tables use `softDeletes()` consistent with departments, programs, academic_years, colleges, campuses.
- Historical records preserved: soft delete hides from listings but keeps row for audit/history.
- Hard delete only via college cascade (college itself soft deletes, so hard delete is rare, e.g., platform cleanup). Applicant hard delete cascades to enquiries/applications (hard delete) — but since applicant soft deletes, hard delete only when college hard deleted.
- No `ON DELETE CASCADE` for academic_year or program: use `nullOnDelete()` to preserve enquiry/application even if year/program deleted (soft deleted in practice). This avoids losing admission history.
- Audit logs are append-only, never updated/deleted (existing `AuditLog` booted prevents update/delete).
- Future: application status transitions (e.g., admitted) do NOT delete application; admission record is new row referencing application.

---

## 8. Potential Conflicts / Ambiguities & Decisions

1. **Table naming**: Decided `admission_applicants`, `admission_enquiries`, `admission_applications` (all prefixed) for clear domain grouping. Alternative `applicants` generic could be more reusable, but prefix avoids collision and matches modular monolith bounded context. If Student Management needs generic person, it can still reference `admission_applicants` or we can later extract to `persons`. Documented as decision, open for review.

2. **Applicant required for enquiry?** Yes, to avoid duplication. Enquiry without applicant would duplicate person fields. Foundation enforces `applicant_id` NOT NULL in enquiries. If business needs to capture enquiry before full applicant details, create minimal applicant (name, phone) then enquiry. This is stricter but avoids duplication. Alternative is nullable applicant_id + person fields in enquiry (duplication). Chose strict to enforce single source of truth.

3. **Person fields in enquiry/application?** No, to avoid duplication. All person data lives in `admission_applicants`. Enquiry/application show person via relationship.

4. **Academic year required?** DB nullable (nullOnDelete) to preserve history if year deleted, but future FormRequest will require it for creation. Same for program_id nullable in DB, required in business logic depending on institution.

5. **Enquiry number / Application number generation**: Unique per college, not per year. Generation logic (e.g., `ENQ-2026-0001`, `APP-2026-0001`) will be in future Action/Service with college-row lock for concurrency, similar to AcademicYear overlap check. For foundation, just string field with unique constraint.

6. **Multiple applications per applicant**: Allowed. Design supports `applicant_id + academic_year_id` not unique, so applicant can apply to multiple programs/years. If business rule later says one application per applicant per year, add unique index `college_id + applicant_id + academic_year_id + program_id` or enforce in service layer, not DB, to keep flexible.

7. **Gender, source, status as string vs enum**: Use string with validation in FormRequest (`in:...`) to avoid DB-level enum that is hard to change and may be institution-specific. Consistent with Department/Program status string.

8. **Created_by / Updated_by**: AcademicYear has them, Departments/Programs do not. For admission (transactional data), we include them as nullable FKs for audit, consistent with AcademicYear. If team prefers not to, can be removed — low impact.

9. **Document, Merit, Admission, Fee tables**: Explicitly NOT created in foundation to avoid premature coupling. Foundation design ensures they can reference `college_id`, `applicant_id`, `application_id`, `academic_year_id`, `program_id` without changes.

10. **Polymorphic vs explicit FKs**: Avoid polymorphic for admission; use explicit FKs for clarity and query performance.

11. **JSON fields**: Avoid JSON for normalized fields; use explicit columns.

12. **TenantContext in tests**: Models rely on TenantContext for auto-filling college_id. Tests must set context via `TenantContext::set()` or via `BelongsToCollege` creating with explicit college_id (as existing tests do via `withoutGlobalScopes()` or direct create). For foundation tests, we will use `withoutGlobalScopes()` creation and also test scoped queries.

13. **Permissions**: Not created in foundation, but future permissions like `admission_enquiries.view`, `admission_applications.create`, etc., will be needed. Seeder will need update later.

---

## 9. Implementation Plan for Foundation (This Task)

- Create 3 migrations:
  - `2026_09_16_000002_create_admission_applicants_table.php`
  - `2026_09_16_000003_create_admission_enquiries_table.php`
  - `2026_09_16_000004_create_admission_applications_table.php`

- Create 3 models:
  - `App\Models\AdmissionApplicant`
  - `App\Models\AdmissionEnquiry`
  - `App\Models\AdmissionApplication`
  - Each uses `HasFactory, SoftDeletes, BelongsToCollege`, fillable, casts, relationships.

- No controllers, routes, UI, FormRequests, Policies yet (deferred to next tasks per roadmap).

- Add focused tests:
  - `tests/Feature/Admissions/AdmissionFoundationTest.php` covering tenant isolation, unique constraints, soft deletes, relationships, college_id auto-fill, cross-college protection.

- Run full test suite to ensure no regression (existing 65 passed).

---

## 10. Open Questions for Review Before Next Phase

- Confirm table naming: `admission_applicants` vs `applicants`. Preference for prefixed for domain clarity, but if Student Management wants generic `persons`, we may need to refactor.
- Confirm `applicant_id` required in enquiries: strict vs flexible. Strict avoids duplication but requires applicant creation at enquiry time.
- Should `admission_applicants` include more person fields now (e.g., father_name, mother_name, category, etc.) or keep minimal and add via migrations later? Minimal now to avoid institution-specific hard-coding.
- Should we add `department_id` to applications for faster filtering, or rely on program->department? Rely on program to avoid duplication.
- Should enquiry/application numbers be generated via DB sequence or application service with college lock? Recommend service with lock, similar to AcademicYear.
- Should we add `admission_cycles` table to group academic_year + program + dates, or is AcademicYear sufficient for now? AcademicYear sufficient per current architecture, but admission cycle could be future.
- Confirm status values: keep open string vs predefined enum list? Recommend string with validation list in future FormRequest, not DB enum.
- Confirm audit logging: should we log applicant PII with redaction? Existing AuditLogService redacts sensitive keys; ensure email/phone not redacted as sensitive? Currently redaction list includes password, token, secret, etc., not email/phone, so okay.

---

## 11. References

- Existing docs: `docs/architecture.md`
- Existing migrations: `departments`, `programs`, `academic_years`
- Existing traits: `BelongsToCollege`, `CollegeScope`
- Existing tests: `DepartmentTenancyTest`, `ProgramTenancyTest`, `DepartmentManagementTest`, `ProgramManagementTest`
