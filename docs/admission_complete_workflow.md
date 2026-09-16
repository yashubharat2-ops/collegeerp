# Admission Module — Complete Workflow (Phase 2)

**Date:** 2026-09-16
**Branch:** arena/01a0aa8e-collegeerp
**Status:** Full admission workflow implementation

## Overview

This milestone completes the Admission module beyond foundation:

- Dashboard
- Enquiries (existing)
- Applicants (existing)
- Applications (existing + admitted status + workflow)
- Documents & Verification
- Merit / Selection
- Final Admission / Enrollment
- Reports
- RBAC, Tenant Isolation, Audit, Deterministic Ordering

## New Tables & Models

### 1. admission_document_types
Master list of document types per college.
- `college_id` FK cascade, `code` unique per college, `name`, `description`, `is_required` bool, `allowed_extensions`, `allowed_mimes`, `max_size_kb`, `status` active/inactive, `created_by`, `updated_by`, softDeletes.
- Model: `AdmissionDocumentType` with `BelongsToCollege`, relationships to documents and programs (pivot).
- Purpose: Flexible, no hard-coded college rules. Allowed extensions/mimes configurable.

### 2. admission_documents
Uploaded files with verification workflow.
- `college_id`, `applicant_id` FK cascade, `application_id` nullable FK nullOnDelete, `document_type_id` FK cascade, `file_path` server-generated, `original_filename`, `mime_type`, `file_size`, `verification_status` pending/verified/rejected, `verified_by`, `verified_at`, `rejection_remarks`, `uploaded_by`, `remarks`, softDeletes.
- Security: file_path = `admissions/{college_id}/{applicant_id}/{uuid}.{ext}` — never trust original filename. Stored on `private` disk (storage/app/private), not publicly accessible. Download via controller that checks tenant scope + permission + prevents path traversal (`..`, leading `/`). MIME/size validated in FormRequest + type config.
- Re-upload: new file replaces old, resets verification to pending, deletes old file.
- Model: `AdmissionDocument` with `BelongsToCollege`.

### 3. admission_document_type_program (pivot)
Per-program document requirements.
- `college_id`, `program_id`, `document_type_id`, `is_required` bool, unique composite.
- Allows per-program required/optional override.

### 4. admission_merit_lists
Grouping for merit entries per academic year/program.
- `college_id`, `academic_year_id` nullable nullOnDelete, `program_id` nullable nullOnDelete, `name`, `code` unique per college, `description`, `status` draft/published, `is_published` bool, `published_at`, `published_by`, `remarks`, softDeletes.
- Model: `AdmissionMeritList` with `BelongsToCollege`, hasMany entries.
- Publish/unpublish actions with audit.

### 5. admission_merit_entries
Individual ranking.
- `college_id`, `merit_list_id` FK cascade, `application_id` FK cascade, `applicant_id` FK cascade (denormalized for quick access + tenant check), `merit_score` decimal 10,2 nullable, `rank` int nullable, `selection_status` pending/selected/waitlisted/rejected, `remarks`, softDeletes.
- Unique `merit_list_id + application_id` prevents duplicate entry per list.
- Flexible: no hard-coded formula, score and rank explicit.
- Model: `AdmissionMeritEntry` with `BelongsToCollege`.

### 6. admissions (final enrollment)
Final admission record derived from approved/selected application.
- `college_id`, `academic_year_id` nullable nullOnDelete, `program_id` nullable nullOnDelete, `application_id` FK cascade unique per college, `applicant_id` FK cascade, `admission_number` string unique per college, `admission_date` date, `status` active/cancelled/completed, `remarks`, softDeletes.
- Admission number generated server-side via `GenerateAdmissionNumber` (similar to GenerateApplicationNumber) using college row lock for concurrency safety: `ADM-{YEAR}-{SEQ}`.
- Duplicate prevention: unique `college_id + application_id` and `college_id + admission_number`.
- Integration boundary: keeps `applicant_id` as single source of truth for future Student module (Student will reference applicant_id and optionally admission_id, without duplicating person data).
- Model: `Admission` with `BelongsToCollege`.

## Workflow & Status

### Application Workflow
New service `AdmissionApplicationWorkflow`:
- Statuses: draft, submitted, under_review, approved, rejected, cancelled, admitted
- Transitions configurable map, extensible, not hard-coded to one institution.
- Controller enforces `canTransition(from, to)` and returns validation error if invalid.
- Permissive to support existing tests (draft->under_review, submitted->draft allowed), but prevents clearly invalid (admitted->draft, rejected->approved).
- `admitted` is terminal, set when admission created (service updates application status to admitted).

### Document Verification
- pending -> verified (sets verified_by, verified_at)
- pending/verified -> rejected (sets rejection_remarks, verified_by, verified_at)
- Re-upload resets to pending.

### Merit / Selection
- Merit list draft -> published (sets published_at, published_by, is_published)
- Entry selection_status: pending, selected, waitlisted, rejected.

### Final Admission
- Only approved/submitted/under_review/admitted applications can be admitted (blocks draft/rejected/cancelled).
- Service `AdmissionService` handles transactional creation, duplicate check, number generation, application status transition, audit.

## Dashboard

Controller `AdmissionDashboardController` (single action):
- Tenant-scoped counts: total enquiries, applicants, applications, admissions, draft, submitted, under_review, approved, rejected, admitted, cancelled, pending/verified/rejected/total documents.
- Program-wise application counts (group by program_id)
- Academic-year-wise counts (group by academic_year_id)
- Status breakdown.
- No unnecessary analytics framework, efficient simple queries.

## Reports

Controller `AdmissionReportController`:
- Filters: academic_year_id, program_id, application status (tenant-scoped validation).
- Sections:
  - Application status summary (counts per status)
  - Program-wise: total, approved, admitted
  - Academic-year-wise: total, approved, admitted
  - Document verification status counts
  - Merit/selection status counts
  - Admissions status counts
  - Filtered applications list (paginated, deterministic ordering)
  - Admitted students/applications list.

## RBAC

New permissions added to `DatabaseSeeder`:

- admission_dashboard.view
- admission_documents.view/create/update/delete/verify
- admission_document_types.view/create/update/delete
- admission_merit.view/create/update/delete/publish
- admissions.view/create/update/delete
- admission_reports.view

Naming follows existing convention `module.action`.

Policies:
- AdmissionDocumentTypePolicy, AdmissionDocumentPolicy, AdmissionMeritListPolicy, AdmissionMeritEntryPolicy, AdmissionPolicy
- All check `hasPermission(..., college_id)` and use scoped queries (404 not 403 for cross-college).

### Seeding / Backfill for Existing Local Databases

Fresh tests seed permissions via `TestCase::setUp()` calling `$this->seed()`, so tests always have latest permissions.

Existing local databases may be stale (missing new permissions). Safe backfill process (DO NOT use migrate:fresh):

```bash
# Option 1: Re-run DatabaseSeeder (idempotent, uses firstOrCreate)
php artisan db:seed

# Option 2: Run only permission backfill (if you have custom seeder)
php artisan db:seed --class=DatabaseSeeder

# Verify permissions exist
php artisan tinker
>>> Permission::where('slug','like','admission_%')->pluck('slug')
```

`DatabaseSeeder` uses `firstOrCreate` for permissions and `sync` for roles, so re-running is safe and does not duplicate or delete data. It will add new permissions and attach to super-admin and college-admin roles.

For multi-college setups, if you have custom roles, manually attach new permissions via UI or tinker:

```php
$role = Role::where('slug','your-role')->first();
$perms = Permission::whereIn('slug', ['admission_documents.view', ...])->pluck('id');
$role->permissions()->syncWithoutDetaching($perms);
```

Never use `migrate:fresh` in production/staging as it destroys data.

## Tenant Isolation

- Every new model/table has `college_id` where tenant-owned, uses `BelongsToCollege` trait and `CollegeScope` global scope.
- Controllers use `TenantContext::id()` for college_id, never trust browser `college_id` (stripped in FormRequest `prepareForValidation`).
- Related IDs validated against active college via `Rule::exists(...)->where('college_id', $collegeId)`.
- Cross-college access returns 404 (scoped query `findOrFail`), not 403 leak.
- File access also tenant-scoped: download checks college_id match, prevents path traversal (`..`, `/`).

## Audit

Uses existing `AuditLogService`:
- document upload, re-upload, verification/rejection, download
- document type create/update/delete
- merit list create/update/delete/publish/unpublish
- merit entry create/update/delete
- admission create/update/cancel/delete
- application status change via admission creation

No sensitive file content or passwords stored in audit.

## Deterministic Ordering

All Admission list pages have explicit secondary ordering:

- Applicants: `first_name, last_name, id`
- Enquiries: `created_at DESC, id DESC`
- Applications: `created_at ASC, id ASC` (creation-order pagination)
- Documents: `created_at DESC, id DESC`
- Document Types: `name ASC, id ASC`
- Merit Lists: `created_at DESC, id DESC`
- Merit Entries: `rank ASC, merit_score DESC, id ASC` (within list show), `created_at DESC, id DESC` (global index)
- Admissions: `admission_date DESC, created_at DESC, id DESC`
- Reports: `created_at ASC, id ASC` for applications, `admission_date DESC, id DESC` for admissions

## UI Navigation

Updated `layouts/app.blade.php`:

Admission
  Dashboard
  Applicants
  Enquiries
  Applications
  Documents
  Document Types
  Merit / Selection
  Admissions
  Reports

Uses existing Blade conventions, Tailwind classes `panel`, `panel-title`, `panel-subtitle`, `input`, `button`, `alert-success`, etc.

## Testing

New Feature tests:

- AdmissionDashboardTest
- AdmissionDocumentTypeManagementTest
- AdmissionDocumentManagementTest
- AdmissionDocumentTenancyTest
- AdmissionDocumentAuthorizationTest
- AdmissionMeritManagementTest
- AdmissionMeritTenancyTest
- AdmissionMeritAuthorizationTest
- AdmissionManagementTest
- AdmissionTenancyTest
- AdmissionAuthorizationTest
- AdmissionWorkflowTest
- AdmissionReportsTest

Coverage:
- CRUD, validation, unique per college, server-controlled fields, tenant isolation, cross-college 404, authorization (guest, no perm, view vs delete), duplicate prevention (admission per application, merit entry per list), workflow transitions (valid/invalid), document security (private storage, path traversal, mime/size, re-upload resets verification, file deletion), private file access, XSS-safe UI (escaped output), audit logging, pagination/filtering, deterministic ordering, merit publish/unpublish, final admission behavior (number generation, sequential per college, application status transition to admitted).

Existing tests still pass (135 + new).

## Code Quality

- Thin controllers, FormRequests, Policies, Domain Actions/Services for business logic.
- Eloquent relationships, reusable validation.
- No unnecessary packages, no unrelated refactoring, no hard-coded college-specific rules.
- Migrations only, no destructive migrations, proper indexes and foreign keys, SoftDeletes where appropriate, nullable FKs only for historical preservation.

## Known Non-Blocking Limitations

- Program-document requirements pivot table exists but no dedicated UI for per-program configuration; can be managed via tinker or future UI. Foundation is ready.
- Merit score calculation is manual (no auto-calculation formula); design allows future configurable criteria without core change.
- Admission number format is `ADM-{YEAR}-{SEQ}` per college, not per academic year sequential, but includes year part from academic year code for readability.
- Document type allowed_extensions/mimes stored as comma-separated strings for simplicity, not JSON; validation normalizes lower case.
- No file virus scanning; MIME validation only.
- Reports are simple paginated tables, not exportable CSV/PDF (future enhancement).
- Student module integration boundary is clean (applicant_id) but no automatic Student creation yet (intentionally deferred).

## PR

- Branch: arena/01a0aa8e-collegeerp
- Title: feat: complete admission management workflow
- Commits: multiple logical commits for migrations, models, services, policies, requests, controllers, views, tests, seeder, navigation
- Final steps: run `php artisan test` and `npm run build` before PR creation.
