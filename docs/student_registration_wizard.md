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
>
> **Only the entry point creates a draft.** `GET students/registration` starts or
> resumes the authenticated user's own draft; opening `students/registration/step/{n}`
> without one redirects to the entry point instead of creating it as a side effect
> of a read. A rejected save that had no draft behind it therefore returns to the
> step it failed on, which forwards to the entry point with the failure (errors,
> old input and the step number) kept alive, so the same form reopens with the
> message still on screen. A `draft_id` belonging to another user — or another
> college — is a 404 on every route, and never leaves a replacement draft behind.

## 2. Steps and fields

### Step 1 — Basic Information

Five sections, in this order, with plain headings (the five-step bar is the only
numbering in the wizard):

1. **Personal Details** — first / middle / last name, date of birth, gender,
   category, mother tongue, blood group, nationality, mobile, alternate mobile,
   email. **Required: first name only** — the canonical Student rules are
   unchanged; no optional field was made mandatory for the wizard.
   Identity numbers live here too: Aadhaar (encrypted at rest, masked on screen),
   APAAR/ABC ID (12 digits), other government ID (type + number pair).
2. **Permanent Address** — country → state → district → block/tehsil →
   village/city, gram panchayat/ward, postal code, address lines 1–2.
3. **Temporary Address** — the correspondence address with **Same as permanent
   address** in its header. When ticked, the correspondence columns are stored as
   `NULL` and the flag drives the display — the address is never copied twice.
4. **Photograph** — student photograph, plus a compact sub-block for the father,
   mother and guardian photographs.
5. **Signatures** — the student signature.

All five images are JPG/PNG/WebP, ≤ 2 MB, with preview + replace + remove, stored
by `StudentPhotoService` on the private disk under a server-generated name.

The card chrome is compact (tighter paddings, 12-column grid, sticky action bar)
and uses the Deep Blue / Navy tokens: primary `#1D4ED8`, hover `#1E40AF`, navy
`#0F172A`, page `#F8FAFC`, surface white.

**Save draft vs Save & continue.** Every step — step 1 included — has a visible
outlined **Save draft** button next to the primary **Save & continue**. Save draft
posts to `students/registration/step/{step}/draft`: it validates the step
leniently, stores it, keeps the applicant on the step they are editing
(`current_step` does not move) and never submits. Save & continue validates
strictly and advances. Both are the same controller action with a different
`advance` flag, so the two can never drift.

**Dependent selectors.** The hierarchy is `geo_locations`
(country → state → district → block → village, self-referencing `parent_id`).

* `database/data/geo_locations.json` ships the country (**India**, `IN`) and its
  **36 states / union territories** — public administrative units with their
  official names and ISO 3166-2 codes. Load them with
  `php artisan db:seed --class=GeoLocationSeeder` (idempotent; re-running adds
  only what is missing).
* Districts, blocks and villages are **not** shipped: they must come from the
  college's own administrative source. Point `GEO_LOCATION_SOURCE` at a JSON file
  or a directory of JSON files and re-run the seeder; the file format is the same
  tree documented in the seeder's header.
* **Country defaults to India** when the directory holds it, so the state list is
  populated instead of showing an empty box. A level becomes selectable as soon
  as its *parent* has a value.
* While a level has no data the wizard renders a text input bound to the existing
  student column, with a visible note, so the form is usable with an empty
  directory.
* A parent change clears the child selects, and the ids are verified as a chain
  server-side by `GeoLocationService::resolveChain()` — a village from another
  block is a validation error, never a silent correction.
* A resumed draft re-selects its saved chain; `GeoLocationService::selection()`
  also fills in an ancestor that is missing from an older payload.

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
  grade, grade letter, result status, remarks.
  * **`+ Add Qualification`** (in the card header) appends another row in place,
    without navigating away. Rows are independently editable and removable; a
    removed row that is already stored is flagged `remove=1` and deleted on save,
    so deleting one row never corrupts the others. A new row gets `max + 1` as its
    index, so indices stay stable for the life of the form.
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

Two compact parts, so the screen is a picker plus the cards that were picked:

**A. Select documents** — one checkbox per document type, in a responsive grid
(`.erp-doc-picker`). The list is built from the configuration that already exists:
`admission_document_types` (per-college master: allowed extensions/MIMEs, max
size, global `is_required`) + the `admission_document_type_program` pivot
(`is_required`, `can_submit_later`).

* An explicit pivot row wins over the global flag (so a course can make a
  globally-required document optional, or vice versa).
* `can_submit_later = true` marks a document as "may be submitted after
  admission" — it does not block submission.
* A document required by the selected course is rendered **ticked and locked**
  (`disabled`) with a `Required` chip, so un-ticking can never make the
  requirement disappear. Optional and deferred types are the applicant's choice.
  Each row also shows `Uploaded` / `Not uploaded`.
* Nothing is hard-coded: the picker only ever lists configured types, and an empty
  master shows a clear "no document types configured" state.

**B. Attach documents** — one compact card per *selected* type, each with its own
upload form (type, file, remarks, Attach) or, when a file already exists, the
file link + `Replace` / `Remove`. Ticking a box reveals its card; un-ticking hides
only an *empty* card. **A card that holds a staged file is never hidden and its
file is never discarded by a toggle** — removal is the explicit `Remove` action.
A collapsed "attach another document" section keeps the untyped/other upload
available without crowding the list.

* The requirement check at submission reads the **stored files**, never the
  checkbox state, so the browser cannot bypass a mandatory document
  (`RegistrationSubmissionService::assertDocumentRequirements()`).
* Status is always **awaiting verification** until a human verifies it in the
  Documents module.
* Files are stored privately under
  `students/{college_id}/registration-drafts/{draft_id}/{uuid}.{ext}`, streamed
  through authorised routes only, and adopted (same blob) into `student_documents`
  on submission.

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

* **Districts, blocks and villages are not shipped.** The seeder loads India and
  its 36 states / union territories from `database/data/geo_locations.json`; the
  deeper levels must be imported from the college's own administrative source
  (`GEO_LOCATION_SOURCE`, JSON tree — see the seeder header). Until then those
  levels stay free-text and the page says so. No place name is invented.
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

`tests/Feature/Layout/RegistrationNavigationTest.php` pins the sidebar: the
Registration row opens the wizard, the Student List row keeps its own
destination, exactly one of them is highlighted (including on all five wizard
steps), and there is only one Registration row.

`tests/JavaScript/registration-wizard.test.js` covers the client-side
conveniences: the address cascade clearing children and reporting an empty
directory, guardian auto-fill, marks/percentage arithmetic, adding and removing
qualification rows, and the document picker showing/hiding upload cards while
never hiding a card that holds a file.

`tests/Feature/Students/StudentRegistrationApprovalTest.php` was updated for the
new entry point (the wizard) and continues to cover the single-POST flow,
approval effects and pending-student visibility.
