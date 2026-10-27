# Student Registration Wizard

Five steps, in this exact order:

1. **Basic Information**
2. **Parent/Guardian**
3. **Academic Record**
4. **Attach Documents**
5. **Final Review → Submit for Approval**

Entry point: `GET /students/registration` (`students.registration.create`).
The old single-page registration form is replaced by the wizard; the Create/Edit
profile form (`students.create`) is unchanged for every other flow.

---

## 1. What the wizard guarantees

| Guarantee | How |
| --- | --- |
| A half-filled registration is **not** a student | Everything before the final submit lives in `student_registration_drafts` (per college + per user). No `students`, `student_enrollments`, `student_documents` or `approval_requests` row exists until step 5 is submitted. |
| Nothing typed is lost | Every step has **Save draft**; drafts persist across steps and sessions and resume on `current_step`. |
| One submission, one record | The draft flips to `submitted` and stores `student_id` + `approval_request_id`; a second submit returns the existing registration instead of creating another. |
| Approval is still the only way in | Submission calls the existing `StudentRegistrationService` → one pending `Student` (`status = inactive`, `registration_status = pending_approval`) + **one** `ApprovalRequest` (module `student`, action `registration_approval`). The approval side effects (activate student, create the enrollment) remain in `ApprovalService`. |
| No enrollment before approval | Step 3 captures the academic context only; `ApprovalService::approveStudentRegistration()` creates the `StudentEnrollment` when an approver decides. |
| Uploading is never verifying | Adopted documents are written with `verification_status = pending`; only an authorised verify action in the Documents module changes that. |
| A rejected request leaves no trace | Opening the wizard (`GET students/registration`) starts or resumes exactly one draft. A step save creates the row only after every check has passed, so a registration rejected for a duplicate phone/Aadhaar or a mismatched address chain leaves no draft row, no audit entry and no stored file. |

> **Draft lifecycle.** One live draft per (college, user). It is created either by
> the wizard entry point or by the first *successful* step save, and is reused —
> never duplicated — on every later save. A rejected save neither creates a new
> row nor overwrites the payload already stored.

## 2. Steps and fields

### Step 1 — Basic Information
* Personal: first / middle / last name, date of birth, gender, category, mother
  tongue, blood group, nationality, mobile, alternate mobile, email.
  **Required: first name only** — the canonical Student rules are unchanged; no
  optional field was made mandatory for the wizard.
* Identity: Aadhaar (encrypted at rest, masked on screen), APAAR/ABC ID
  (12 digits), other government ID (type + number pair).
* Media: student photograph, student signature, father photograph, mother
  photograph, guardian photograph — JPG/PNG/WebP, ≤ 2 MB, preview + replace +
  remove, stored by `StudentPhotoService` on the private disk with a
  server-generated name.
* Permanent address: country → state → district → block/tehsil → village/city,
  gram panchayat/ward, postal code, address lines 1–2.
* Correspondence address with **Same as permanent address**. When ticked, the
  correspondence columns are stored as `NULL` and the flag drives the display —
  the address is never copied twice.

**Dependent selectors.** The hierarchy is `geo_locations`
(country → state → district → block → village, self-referencing `parent_id`).
It ships **empty on purpose**: no state/district/village names are invented by
this application. While a level has no data the wizard renders a text input
bound to the existing student column; as soon as an administrator loads the
official directory (`GeoLocationSeeder`), that level becomes a dependent select
(children only, parent change clears children, ids verified as a chain
server-side by `GeoLocationService::resolveChain()`).

### Step 2 — Parent/Guardian
* Father and mother are separate records: name, mobile, email, occupation
  (their photographs come from step 1 and are linked, not re-uploaded).
* Guardian relation: **Father / Mother / Self / Other**.
  * Father, Mother, Self → `guardian_name`, `guardian_phone`,
    `guardian_email`, `guardian_occupation` are **derived on the server** from
    that person's record, so an auto-filled value can never drift from its
    source (a tampered value in the POST is overwritten).
  * Other → the guardian block is independently maintained and stored exactly as
    entered. Switching from a derived relation to Other keeps the values that
    were on screen (the browser submits them), so no data is lost.
  * Consistency rule: choosing Father/Mother/Other without that person's name is
    refused at submission (it would store a guardian identifying nobody). With no
    relation chosen, nothing extra is demanded — this is a consistency check, not
    a newly mandatory field.

### Step 3 — Academic Record
Two blocks that are deliberately **never mixed**:

* **Previous qualifications** → `student_qualifications` (new table).
  Level (Class 10 / Class 12 / Graduation / Post Graduation / Other),
  qualification, exam year, board/university, institution, roll no,
  registration no, subjects (structured JSON, entered as
  `Name | max | obtained` lines), marks obtained, maximum marks, percentage,
  grade, grade letter, result status, remarks. Add / edit / remove any number of
  rows.
  * `percentage` is **derived** from a valid marks pair only; a grade-based
    result keeps its grade and no percentage is written (a supplied one is
    discarded).
  * This is why it is not `student_academic_records` (this college's progression
    ledger, result-agnostic) and not `exams`/`exam_results` (this college's own
    examinations).
* **Current enrollment context** → academic year, programme/course, section /
  batch, semester / year of study, admission date, enrollment date. Every id is
  tenant-scoped, and the section's academic-year + programme pair is verified
  server-side. The admission category is shown read-only because it is captured
  once, on step 1.

### Step 4 — Attach Documents
* Course-wise requirements come from the configuration that already exists:
  `admission_document_types` (per-college master: allowed extensions/MIMEs, max
  size, global `is_required`) + the `admission_document_type_program` pivot
  (`is_required`, and the new `can_submit_later`).
  * An explicit pivot row wins over the global flag (so a course can make a
    globally-required document optional, or vice versa).
  * `can_submit_later = true` marks a document as "may be submitted after
    admission" — it does not block submission.
* Each document: type, title, file, remarks, download (authorised stream),
  replace, remove. Status is always **awaiting verification** until a human
  verifies it.
* Files are stored privately under
  `students/{college_id}/registration-drafts/{draft_id}/{uuid}.{ext}` and adopted
  (same blob) into `student_documents` on submission.

### Step 5 — Final Review
* Four groups (Basic Information / Parent-Guardian / Academic Record /
  Documents), each with its own **Edit** link back to the owning step.
* Pre-submit validation re-runs every step in strict mode against the **stored**
  draft, plus: guardian consistency, qualification arithmetic, academic-context
  hierarchy, course-wise required documents, and identity uniqueness (against the
  decrypted numbers).
* A failure sends the applicant to the step that owns the field, with field-level
  errors.
* Final remarks + a declaration checkbox. Submitting raises the pending student
  and the single approval request.

## 3. Schema added

| Migration | Purpose |
| --- | --- |
| `2026_10_28_000001_create_student_qualifications_table` | Previous/external qualifications per student (marks, grade, subjects JSON, soft deletes). |
| `2026_10_28_000002_create_registration_wizard_tables` | `student_registration_drafts`, `student_registration_draft_documents`, `geo_locations`. |
| `2026_10_28_000003_add_registration_profile_columns_to_students_table` | Parent mobiles/emails/occupations; signature + father/mother/guardian photo paths; district, block/tehsil, gram panchayat/ward; optional `geo_locations` ids; correspondence address block + same-as-permanent flag. |
| `2026_10_28_000004_add_submission_timing_to_admission_document_type_program_table` | `can_submit_later` on the existing pivot. |
| `2026_10_28_000005_add_semester_to_student_enrollments_table` | Year-of-study / semester of one enrollment. |

All columns are nullable and additive; no existing column changed its meaning.

## 4. Routes

| Method | URI | Name |
| --- | --- | --- |
| GET | `students/registration` | `students.registration.create` (starts/resumes the wizard) |
| GET | `students/registration/step/{step}` | `students.registration.step` |
| POST | `students/registration/step/{step}` | `students.registration.step.save` (Next) |
| POST | `students/registration/step/{step}/draft` | `students.registration.step.draft` (Save draft) |
| POST | `students/registration/documents` | `students.registration.documents.upload` |
| POST | `students/registration/documents/{document}` | `students.registration.documents.replace` |
| POST | `students/registration/documents/{document}/delete` | `students.registration.documents.destroy` |
| GET | `students/registration/documents/{document}/download` | `students.registration.documents.download` |
| GET | `students/registration/image/{field}` | `students.registration.image` (draft photo preview) |
| GET | `students/registration/geo/children` | `students.registration.geo.children` |
| POST | `students/registration/submit` | `students.registration.submit` |
| POST | `students/registration/cancel` | `students.registration.cancel` |

`POST students/registration` (`students.registration.store`) is **kept** for
existing callers: it runs the same `StudentRegistrationService` and the same
approval flow.

## 5. Security notes

* Aadhaar and government ID numbers are stored **encrypted** inside the draft
  payload (Crypt / `APP_KEY`) with only the four-digit tail in clear text; they
  are decrypted in memory at submission, never rendered, logged or put in the
  approval payload.
* Draft photo previews and document downloads are authorised controller actions
  streaming from the private disk — no stored path is exposed in a URL.
* Every draft query is scoped to `college_id` **and** `user_id`; another user's
  draft id is a 404.
* Every action requires `students.create`; the college is the tenant context, not
  a form field.

## 6. Known limitations / decisions

* **Geography directory is empty.** No country/state/district/block/village rows
  are seeded by this change (inventing official names is worse than an empty
  table). Until an administrator loads the directory, the address is free text.
  `GeoLocationSeeder` is the extension point.
* **No qualification is mandatory.** The canonical Student rules never required
  previous education, so the wizard does not demand a qualification row; rows
  that exist are fully validated. If a college requires at least one, that is a
  business rule to confirm before it is enforced.
* **Semester is free text.** The schema holds no semester master; a free-text
  column works for annual, semester and trimester systems. A master can replace
  it later without changing the wizard's contract.
* **Document types are not seeded.** The wizard shows "no document types
  configured" and treats everything as optional until Admission → Document Types
  is populated.

## 7. Tests

`tests/Feature/Students/RegistrationWizardTest.php` covers navigation and step
validation, draft persistence/resumption, the dependent address chain,
same-as-permanent correspondence, guardian auto-fill (Father/Mother/Self/Other),
multiple qualifications with marks arithmetic and grade preservation,
course-wise required/deferred documents, upload authorization (foreign college
type) and privacy, submission through the approval framework, duplicate
submission prevention, tenancy isolation and permission checks.

`tests/Feature/Students/StudentRegistrationApprovalTest.php` was updated for the
new entry point (the wizard) and continues to cover the single-POST flow,
approval effects and pending-student visibility.
