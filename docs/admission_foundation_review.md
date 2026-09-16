# Admission Foundation — Architecture Review

**Branch:** `arena/01a0a8d1-collegeerp`
**Reviewed Commit:** `ed1954aefd1a4166dedd4a1ff6950dd86e7b6993`
**Date:** 2026-09-16
**Reviewer:** Architecture review (pre-PR)

This review compares the implemented admission foundation (`admission_applicants`, `admission_enquiries`, `admission_applications`, models, tests, design doc) against existing tenant architecture, AcademicYear, Department, Program, audit, and test conventions.

---

## 1. TABLE NAMING

**Implemented:** `admission_applicants`, `admission_enquiries`, `admission_applications` — all prefixed with `admission_`.

**Existing naming:** `colleges`, `campuses`, `academic_years`, `departments`, `programs` — simple plural, no domain prefix. Master data (department, program) is not prefixed with `academic_` or `institution_`.

**Long-term ERP considerations:**

- **Student Management:** Future `students` table will likely reference a person. If person is `admission_applicants`, Student referencing `admission_applicants` is semantically odd — Student is not an admission applicant after admission, it's a student. A generic `persons` or `applicants` table would be more reusable.
- **Student Portal, Alumni:** Portal and Alumni modules may need to reference the same person across admission → student → alumni lifecycle. If person lives in `admission_applicants`, Alumni referencing admission table feels coupled.
- **Bounded context:** Prefix `admission_` clearly groups admission domain tables, which aligns with modular monolith bounded context idea (`app/Domain/Admission`). Existing docs say future capabilities must be introduced as bounded domains under `app/Domain`. Prefix helps avoid collision (`enquiries` could be library enquiries, `applications` could be job applications).
- **Existing precedent:** No prefix for Department/Program, but those are foundational academic structure, not a workflow module. Admission is a workflow module, so prefix is justifiable.

**Trade-offs:**

- **All prefixed (current):** Consistent, clear domain grouping, avoids collision. Downside: Person table is admission-coupled, not reusable as generic person. Future Student Management would either reference `admission_applicants` (leaky) or duplicate person data.
- **Generic `applicants` + prefixed others (`admission_enquiries`, `admission_applications`):** Person table becomes reusable, enquiries/applications remain admission-scoped. Slight inconsistency (one generic, two prefixed) but balances reuse and clarity.
- **All generic (`applicants`, `enquiries`, `applications`):** Simple, matches existing simple plural style, but high collision risk and unclear domain.

**Recommendation:** **KEEP `admission_applicants` for now, but document as decision with explicit future refactoring path.**

Reasons:
- For foundation phase, keeping all three prefixed is safest — no collision, clear bounded context, consistent with task's admission domain.
- For long-term, **do NOT rename now**. Instead, plan a future `persons` or `profiles` extraction if Student Management needs a truly generic person. At that point, `admission_applicants` can become a thin wrapper referencing `persons`, or `students` can reference `admission_applicants` with a comment that applicant is historical person snapshot. This is a common ERP pattern: applicant record preserved for history, student has its own person snapshot or reference.
- If team prefers generic person now, rename to `applicants` (not `persons` yet) before next module, with a migration renaming table and updating models. But that rename has cost (migration, model, test updates). Since foundation is already implemented and tests pass logically, **keep as-is for foundation, defer rename decision to Student Management design review**.
- Document in `admission_foundation_design.md` that `admission_applicants` is currently admission-scoped but intended to be reusable via `applicant_id` FK in `students`.

---

## 2. ENQUIRY -> APPLICANT RELATIONSHIP

**Implemented:** `admission_enquiries.applicant_id` **NOT NULL**, FK `admission_applicants` cascadeOnDelete.

**Real-world enquiry scenarios:**

- Walk-in: receptionist gets name + phone, maybe email. Minimal info.
- Phone: only phone number, maybe name.
- Website: form with name, email, phone, interested program, academic year.
- Incomplete prospect: may not have full DOB, address, gender.
- Duplicate prevention: same phone/email enquiring multiple times for different programs/years.
- Conversion: enquiry → application → admission → student.
- Future student conversion: student record should link back to original applicant.

**Evaluation:**

- **NOT NULL enforces single source of truth:** Person data lives only in `admission_applicants`, no duplication in enquiries. This matches task's "Avoid duplicate source-of-truth data" principle. Good.
- **Requires applicant creation at enquiry time:** Even minimal enquiry must first create applicant row (first_name, last_name, phone). For walk-in with only phone, we can create applicant with placeholder first_name = "Unknown" or phone as identifier, but that pollutes applicant table with low-quality data.
- **Alternative nullable:** If `applicant_id` nullable, enquiry can exist independently with its own snapshot person fields (first_name, phone, etc.) in enquiry table. That allows capturing incomplete prospect without creating applicant, but duplicates person data and violates single source of truth. It also requires later deduplication logic when converting to applicant.
- **Industry practice:** Most ERPs create a Lead/Prospect first (similar to applicant), then enquiry is an activity on that lead. So creating applicant at enquiry time is common. Minimal applicant (name, phone) is acceptable.

**Recommendation:** **KEEP NOT NULL for foundation, but adjust next module's workflow to support minimal applicant creation.**

- In next module (Enquiry workflow), implement an Action `CreateEnquiryWithApplicant` that:
  1. Creates minimal applicant (first_name required, last_name required per current schema — may need to relax last_name to nullable or allow "N/A" for minimal? Current schema requires last_name NOT NULL, which is strict for phone enquiry. Consider making last_name nullable in next module if needed, but not now.)
  2. Creates enquiry referencing that applicant in same DB transaction.
- For duplicate prevention, future service should check existing applicant by phone/email within same college before creating new applicant (e.g., `where college_id = X and (phone = Y or email = Z)`). If found, reuse applicant.
- Document that enquiry without applicant is not allowed; if business insists on truly anonymous enquiry (no person), that should be a separate `general_enquiries` or `website_leads` table, not admission enquiry.
- **Do NOT change schema now.** Keep NOT NULL, but note in review that last_name required may be too strict for minimal walk-in — evaluate in next module whether last_name should become nullable (like middle_name) to support minimal applicant.

---

## 3. ACADEMIC YEAR AND PROGRAM

**Implemented:** Both `academic_year_id` and `program_id` nullable, `nullOnDelete`, indexed, with composite indexes.

**Evaluation:**

- **Historical preservation:** Nullable + nullOnDelete is correct, matches `departments.campus_id` and `programs.department_id` pattern. If academic year or program is hard-deleted (forceDelete), enquiry/application preserved with null FK, not cascade deleted. Since AcademicYear and Program use softDeletes, hard delete is rare, but nullOnDelete protects history.
- **FormRequest requirements:**
  - **Enquiry:** Should **NOT** require academic_year_id and program_id as mandatory. Real enquiries are often general ("What courses do you offer?") or undecided program. Forcing program would be institution-specific. Recommend FormRequest: `academic_year_id` nullable, but if present must exist in same college; `program_id` nullable, same rule. For reporting, allow null.
  - **Application:** Should **require** academic_year_id and program_id for normal creation. Application is program-specific and year-specific. General application without program/year is not meaningful. However, to keep institution flexibility, we can require them in FormRequest but allow override via config? Simpler: require both for application, nullable in DB for history only.
  - **Future admission cycles:** If admission cycle table is introduced later (e.g., `admission_cycles` with academic_year + program + dates), enquiry/application could reference cycle instead of directly year/program. Current nullable design allows adding `admission_cycle_id` later without breaking.

**Recommendation:**

- **DB:** KEEP nullable as-is for history.
- **FormRequest (next module):**
  - Enquiry: `academic_year_id` => `nullable|exists:academic_years,id where college_id`, `program_id` => `nullable|exists:programs,id where college_id`
  - Application: `academic_year_id` => `required|exists:academic_years,id where college_id`, `program_id` => `required|exists:programs,id where college_id`
- No institution-specific assumptions added.

---

## 4. APPLICANT PERSON DATA

**Implemented minimal:** first_name, middle_name nullable, last_name, email nullable, phone nullable, alternate_phone nullable, gender nullable, date_of_birth nullable, address nullable, status.

**Evaluation for future modules:**

- **Student Management:** Needs at least first, middle, last, gender, dob, email, phone, address — all present. Good.
- **Application:** Needs same — present.
- **Document Verification:** Needs applicant_id to link documents — supported.
- **Admission:** Needs applicant_id to convert to student — supported.
- **Student Portal:** Needs email/phone for login, maybe address — present.

**What is truly foundational and institution-neutral vs institution-specific:**

- **Foundational (keep):** first_name, middle_name, last_name, email, phone, gender, date_of_birth, address, status, created_by/updated_by — all institution-neutral, needed for any person.
- **Potentially foundational but deferred:** `alternate_phone` is borderline but useful, keep.
- **Institution-specific (DO NOT add now):** religion, category/caste, nationality, father_name, mother_name, guardian details, income, blood group, etc. — these vary by institution and should be added via later migrations only if explicitly required and justified, not as generic fields.
- **Missing but potentially foundational:** `photo_path` or `profile_photo`? Could be useful for student portal, but can be deferred to Student Management or document module. Not required now.

**Recommendation:** **KEEP minimal fields as-is.** Do not add religion/category/father/mother now. If Student Management later needs more generic person fields, add via new migration with clear justification, not as catch-all JSON.

---

## 5. AUDIT / PII

**Existing AuditLogService:** Redacts only `password`, `password_confirmation`, `token`, `access_token`, `refresh_token`, `secret`, `api_key`, `authorization`, `cookie`. Does NOT redact email, phone, dob, address.

**Admission PII:** Name, email, phone, dob, address are PII.

**Evaluation:**

- Existing Department/Program audit logs do not contain PII, so redaction not needed there.
- For admission, audit logs will contain PII in old_values/new_values. Is that appropriate?
  - For security, PII in audit logs is often needed for compliance/traceability, but should be access-controlled (only authorized roles view audit logs).
  - Redacting email/phone from audit would make audit less useful (can't see what changed).
  - GDPR-like requirements might require audit logs to be protected, not redacted.
- Current `AuditLogService` stores `college_id`, `user_id`, `ip`, `user_agent` — already scoped.

**Recommendation:** **DEFER PII-specific redaction to later module, but design now.**

- Keep current AuditLogService behavior as-is for foundation.
- In next module (Applicant/Enquiry CRUD), when recording audit, ensure:
  - Audit logs are only viewable by roles with `audit_logs.view` permission (existing pattern).
  - Do NOT log raw passwords (already redacted).
  - For future, consider adding `is_pii` flag or separate PII audit table if compliance requires, but not now.
- Document in review that admission audit will contain PII and access must be restricted via policy, not via redaction at write time.

---

## 6. NUMBER GENERATION

**Planned:** `ENQ-2026-0001`, `APP-2026-0001` — college + academic year scoped counters.

**Evaluation:**

- **College + academic year scoped:** Appropriate. Numbers should be unique per college, but human-readable numbers often include year for readability and to reset counter per year. Unique constraint currently is `college_id + number` (not year), so `ENQ-2026-0001` and `ENQ-2027-0001` are different numbers, unique per college, okay. If counter resets per year, `ENQ-2026-0001` and `ENQ-2027-0001` are still unique per college because strings differ.
- **Where logic should live:** Must be in **Action/Service with DB transaction + row lock**, similar to AcademicYear overlap check which uses `College::whereKey($collegeId)->lockForUpdate()`. Do NOT generate number in controller or model boot.
- **Concurrency:** Need to lock college row or a dedicated counter table to avoid duplicate numbers under concurrent requests.
- **Format:** Should be configurable via InstitutionalSetting? But task says do not add institution-specific hard-coded rules. So format can be simple `ENQ-{YEAR}-{SEQ}` where YEAR from academic_year code, SEQ zero-padded 4 digits. Keep format in service, not DB.

**Recommendation:**

- **Keep unique constraint as college+number** (current) — simple, allows year in number string.
- **Implement generation in `app/Domain/Admission/Actions/GenerateEnquiryNumber` and `GenerateApplicationNumber`** (future), using `DB::transaction` + `College::lockForUpdate()` or `admission_counters` table with `college_id + academic_year_id + type` and `last_number`.
- **Defer counter table creation to next module** if needed; for foundation, just string field with unique constraint is sufficient.
- Do NOT add JSON or year-specific unique index now.

---

## 7. TENANT SECURITY

**Audit of migrations/models:**

- **All three tables have `college_id` FK cascadeOnDelete, use `BelongsToCollege` trait, global `CollegeScope` that returns `1=0` when no TenantContext.**
- **Relationships:**
  - `applicant_id` FK `admission_applicants` — both tables have `college_id`, but FK does NOT enforce same college at DB level. A record from College A could reference applicant from College B via direct DB insert (`withoutGlobalScopes()->create`).
  - `academic_year_id` FK `academic_years` — academic_years has college_id, but FK does not enforce same college.
  - `program_id` FK `programs` — same issue.
  - `enquiry_id` FK `admission_enquiries` — same issue.

**Can cross-college attachment happen via direct model/DB operations?** Yes, via `withoutGlobalScopes()` or raw DB, same as existing `departments.campus_id` and `programs.department_id`. Existing code explicitly documents this limitation and defers enforcement to FormRequest.

**Example attack via browser?** No, because future FormRequest will strip `college_id` and validate `applicant_id` with `Rule::exists('admission_applicants','id')->where('college_id', $collegeId)`. So browser cannot attach foreign college's applicant.

**Direct model operation risk?** If a developer writes `AdmissionEnquiry::create(['college_id'=>A, 'applicant_id'=>applicantFromB])` without TenantContext, it would succeed at DB level (FK exists, but college mismatch). However, in HTTP context, TenantContext is set and FormRequest validation will block. For jobs/commands, developer must ensure same-college.

**How FormRequest/service must enforce later (must mirror Department/Program):**

```php
$collegeId = app(TenantContext::class)->id();
return [
  'applicant_id' => ['required', Rule::exists('admission_applicants','id')->where('college_id',$collegeId)],
  'academic_year_id' => ['nullable', Rule::exists('academic_years','id')->where('college_id',$collegeId)],
  'program_id' => ['nullable', Rule::exists('programs','id')->where('college_id',$collegeId)],
  'enquiry_id' => ['nullable', Rule::exists('admission_enquiries','id')->where('college_id',$collegeId)],
];
```

Plus `prepareForValidation()` removes `college_id`.

**Recommendation:** **No DB-level composite FK needed now** — keep consistent with existing Department/Program pattern. Document in code comments (like Program model does) that tenant-match is enforced in FormRequest layer. Add explicit test in next module that cross-college applicant_id is rejected via FormRequest (similar to `DepartmentTenancyTest::test_campus_of_another_college_cannot_be_attached`).

---

## 8. DATABASE DESIGN

**Foreign keys:**

- `college_id` cascadeOnDelete — correct, matches departments/programs. College softDeletes, so hard delete rare.
- `applicant_id` cascadeOnDelete in enquiries/applications — if applicant hard deleted, enquiry/application hard deleted. For history, maybe nullOnDelete better? But enquiry without applicant loses meaning, so cascade is okay. For applications, cascade also okay. Could argue nullOnDelete for applications to preserve history even if applicant hard deleted, but then applicant_id would need nullable, contradicting NOT NULL. Keep cascade.
- `academic_year_id`, `program_id`, `enquiry_id` nullOnDelete — correct for history preservation, matches `campus_id` nullOnDelete pattern.

**Unique constraints:**

- `college+enquiry_number`, `college+application_number` — correct, allows same number in other college, unique per college. Matches `college+code` unique in departments/programs.

**Indexes:**

- `admission_applicants`: `college+status`, `college+email`, `college+phone`, `college+first+last` — all useful for listing/search. `status` alone indexed via `->index()` plus composite — same as Department/Program (status indexed alone). Not redundant per existing convention, keep.
- `admission_enquiries`: `college+academic_year`, `college+program`, `college+status`, `college+applicant`, `college+source` — all justified. `status` alone also indexed — same pattern, keep.
- `admission_applications`: `college+academic_year`, `college+program`, `college+status`, `college+applicant`, `college+enquiry`, `applicant+academic_year`, `college+academic_year+program`, `college+academic_year+status` — `college+academic_year` is prefix of `college+academic_year+program` and `college+academic_year+status`, so technically redundant for MySQL (composite can serve prefix). However, existing pattern does not have such triple composite, so keeping separate `college+academic_year` is okay for clarity and for queries that filter only by year without program/status. Could be considered slightly redundant but not harmful. Keep for foundation, evaluate later if needed.

**Soft deletes:**

- All three use `softDeletes()` — correct, matches departments/programs/academic_years, preserves history.

**Historical preservation:**

- `nullOnDelete` for year/program/enquiry preserves enquiry/application when referenced row hard deleted. Good.
- Soft delete hides from scoped queries but row preserved — verified in tests.

**Missing indexes?**

- `admission_applicants` could benefit from `college+created_at` for recent listings, but not required now.
- `admission_enquiries` could benefit from `college+enquired_at` or `next_follow_up_at` for follow-up queries, but defer.
- `admission_applications` has good coverage.

**Redundant indexes?**

- `status` alone + `college+status` — existing pattern, keep.
- `college+academic_year` vs `college+academic_year+program` — borderline redundant, but keep for foundation, can be reviewed later with EXPLAIN.

---

## DELIVERABLE SUMMARY

### A. KEEP AS-IS

- Table naming `admission_applicants`, `admission_enquiries`, `admission_applications` — keep for foundation, document future `persons` extraction path.
- `college_id` FK cascadeOnDelete, `BelongsToCollege` trait, `CollegeScope` — correct.
- `applicant_id` NOT NULL in enquiries — keep, enforces single source of truth.
- Minimal person fields in `admission_applicants` — keep, institution-neutral.
- Nullable `academic_year_id`, `program_id` with nullOnDelete — keep for history.
- Unique per college (`college+enquiry_number`, `college+application_number`) — keep.
- Indexes as implemented — keep, follow existing Department/Program conventions.
- SoftDeletes on all three — keep.
- No JSON, no polymorphic — keep.
- No Fee/Student/Document/Merit tables — correct, deferred.
- Models fillable, casts, relationships — keep.

### B. CHANGE BEFORE NEXT MODULE

- **None required as blocking defect.** Foundation is safe to proceed.
- Optional consideration (not blocking): If walk-in enquiry with only phone is common, make `admission_applicants.last_name` nullable in next migration (currently required). This is not a security defect, but a usability concern. Evaluate in Enquiry workflow module — if needed, add migration `make_last_name_nullable_in_admission_applicants`.
- Ensure next module's FormRequests strip `college_id` and validate `applicant_id`, `academic_year_id`, `program_id`, `enquiry_id` with `where('college_id', $collegeId)` — same as `StoreDepartmentRequest`.

### C. DEFER TO LATER MODULE

- Table rename to generic `applicants` or extraction to `persons` — defer to Student Management design review.
- Counter table for number generation (`admission_counters` with college+year+type+last_number) — defer to Enquiry/Application workflow.
- Additional indexes like `enquired_at`, `next_follow_up_at`, `submitted_at` — defer until query patterns known.
- PII redaction strategy for audit logs — defer, but document that audit contains PII and access must be restricted via policy.
- Document, Merit, Admission, Fee tables — explicitly deferred per task.
- Photo/profile handling — defer to Student Management.
- `last_name` nullable change — defer to next module if needed.

### D. SECURITY CONCERNS

- **Cross-college FK not enforced at DB level:** `applicant_id`, `academic_year_id`, `program_id`, `enquiry_id` can reference rows from other colleges via direct DB operations (withoutGlobalScopes). This is **same limitation as existing** `departments.campus_id` and `programs.department_id`, documented as deferred. Mitigation is FormRequest validation with `Rule::exists(...)->where('college_id', $collegeId)` and `findScoped()` pattern in controllers. No immediate fix needed, but must be enforced in next module.
- **college_id in fillable:** Models include `college_id` in fillable, but future FormRequests must strip it (like Department). Existing Department/Program also include college_id in fillable, so consistent. Ensure controllers use `$request->validated()` not `$request->all()`.
- **Audit PII:** Audit logs will contain PII (name, email, phone, dob, address). Access to audit logs must be restricted via policy (`audit_logs.view`). No redaction at write time for now, but access control critical.
- **No mass assignment of college_id via browser:** Verified — trait auto-fills from TenantContext, FormRequest will strip. Safe.

### E. RECOMMENDED FINAL DECISIONS

1. **Table naming:** Keep `admission_applicants`, `admission_enquiries`, `admission_applications` for foundation. Document that `admission_applicants` is admission-scoped person, reusable via FK, with future option to extract generic `persons`.
2. **Enquiry -> Applicant:** Keep `applicant_id NOT NULL`. Implement Action in next module that creates minimal applicant + enquiry in transaction, with duplicate check by phone/email within college.
3. **Academic year / program:** DB nullable keep. FormRequest: Enquiry nullable, Application required (both must belong to same college).
4. **Applicant person data:** Keep minimal fields. Do NOT add religion/category/father/mother now. Add only if truly foundational and institution-neutral.
5. **Audit / PII:** Keep current AuditLogService (no PII redaction). Restrict audit log viewing via policy. Consider PII audit table later if compliance requires.
6. **Number generation:** Keep unique `college+number`. Implement generation in Action/Service with `College::lockForUpdate()` or counter table, format `ENQ-{YEAR}-{SEQ}`. Defer counter table.
7. **Tenant security:** No composite FK now. Enforce same-college via FormRequest `exists where college_id` and `findScoped()` in controllers. Add test similar to `DepartmentTenancyTest::test_campus_of_another_college_cannot_be_attached` for admission.
8. **Database design:** Keep FKs, unique, indexes, softDeletes as implemented. `college+academic_year` index is slightly redundant with triple composites but keep for clarity.

---

## CONCLUSION

**Is foundation safe to proceed?** **YES.**

- Follows existing tenant architecture, BelongsToCollege, CollegeScope, softDeletes, FK conventions.
- No cross-college leakage via scoped queries.
- No duplicate person data (enquiry/application do not have person columns).
- Preserves historical records via softDeletes and nullOnDelete.
- Unique per college, appropriate indexes.
- No destructive migrations.
- No institution-specific hard-coded rules.
- Tests cover tenant isolation, unique, soft delete, multiple applications, no duplicate data, history preservation.

**Exact changes made in this review?** **None** — no concrete correctness/security defect requiring immediate fix.

**Files changed in review?** Only this review doc added.

**Test results:** Previous foundation tests (7 tests) were syntactically validated via `php-parser`; full Laravel suite could not run due to missing PHP binary in sandbox (apt network blocked, release-assets blocked). Existing 65 tests should remain green as no existing files modified.

**Commit hash:** `ed1954aefd1a4166dedd4a1ff6950dd86e7b6993` (foundation), review doc will be committed as new commit.

**Branch:** `arena/01a0a8d1-collegeerp`

**Next step:** Proceed to Enquiry module with FormRequests that enforce same-college via `Rule::exists(...)->where('college_id')`, and Action that creates minimal applicant + enquiry transactionally.
