# Student Registration Wizard

**Four** steps, in this exact order:

1. **Basic Information** — sections A–H, including Parent and Guardian details
2. **Academic Record**
3. **Attach Documents**
4. **Final Review → Submit for Approval**

Entry point: `GET /students/registration` (`students.registration.create`).
The old single-page registration form is replaced by the wizard; the Create/Edit
profile form (`students.create`) is unchanged for every other flow.

> **Why four and not five.** Parent/Guardian used to be a step of its own. It is
> data about the same page of a paper form as the student's own details, so it is
> now sections C and D of Basic Information. Nothing was merged away: the same
> fields, the same rules and the same server-side derivation are used, and drafts
> written by the five-step wizard are mapped onto the new layout (see §7).

---

## 1. What the wizard guarantees

| Guarantee | How |
| --- | --- |
| A half-filled registration is **not** a student | Everything before the final submit lives in `student_registration_drafts` (per college + per user). No `students`, `student_enrollments`, `student_documents` or `approval_requests` row exists until step 4 is submitted. |
| Nothing typed is lost | Every step has **Save draft**; drafts persist across steps and sessions and resume on the step the draft is actually on. |
| One submission, one record | The draft flips to `submitted` and stores `student_id` + `approval_request_id`; a second submit returns the existing registration instead of creating another. |
| Approval is still the only way in | Submission calls the existing `StudentRegistrationService` → one pending `Student` (`status = inactive`, `registration_status = pending_approval`) + **one** `ApprovalRequest` (module `student`, action `registration_approval`). The approval side effects (activate student, create the enrollment, allocate the roll number) remain in `ApprovalService`. |
| No enrollment before approval | Step 2 captures the academic context only; `ApprovalService::approveStudentRegistration()` creates the `StudentEnrollment` when an approver decides. |
| No identifier is minted by the wizard | Opening the wizard, saving a draft or saving a step never creates a Student ID, Admission Number or Roll Number. Each comes from the workflow that owns it (§3). |
| Declaring is never uploading | The step-3 checkboxes record what the applicant *has* (`declared_documents` in the draft). They create no document row, attach no file and verify nothing. |
| Uploading is never verifying | Adopted documents are written with `verification_status = pending`; only an authorised verify action in the Documents module changes that. |
| A rejected request leaves no trace | Opening the wizard (`GET students/registration`) starts or resumes exactly one draft. A step save creates the row only after every check has passed, so a registration rejected for a duplicate phone/Aadhaar/APAAR or a mismatched address chain leaves no draft row, no audit entry and no stored file. |

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

**Eight sections, in this exact order, with plain unnumbered headings** (the
four-step bar is the only numbering in the wizard):

| Section | Heading | Contents |
| --- | --- | --- |
| A | **Student Identification** | Student ID, Admission Number, Roll Number — read-only, `Pending` until the owning workflow allocates them (§3). No input exists for any of them. |
| B | **Personal Details** | first / middle / last name, date of birth, gender, category, mother tongue, blood group, nationality, mobile, alternate mobile, email; Aadhaar (encrypted at rest, masked on screen), APAAR/ABC ID (12 digits), other government ID (type + number pair). **Required: first name only** — the canonical Student rules are unchanged; no optional field was made mandatory for the wizard. |
| C | **Parent Details** | father and mother as separate records: name, mobile, email, occupation (their photographs come from section G and are linked, not re-uploaded). |
| D | **Guardian Details** | relation **Father / Mother / Self / Other** and the guardian's own name, mobile, email, occupation, address. |
| E | **Permanent Address** | country → state → district → block/tehsil → village/city, gram panchayat/ward, postal code, address lines 1–2. |
| F | **Temporary Address** | the correspondence address with **Same as permanent address** in its header. When ticked, the correspondence columns are stored as `NULL` and the flag drives the display — the address is never copied twice. |
| G | **Photograph** | student photograph, plus a compact sub-block for the father, mother and guardian photographs. |
| H | **Signatures** | the student signature. |

All five images are JPG/PNG/WebP, ≤ 2 MB, with preview + replace + remove, stored
by `StudentPhotoService` on the private disk under a server-generated name.

The card chrome is compact (tighter paddings, 12-column grid, sticky action bar)
and uses the Deep Blue / Navy tokens: primary `#1D4ED8`, hover `#1E40AF`, navy
`#0F172A`, page `#F8FAFC`, surface white.

**Guardian derivation (section D).**

* Father, Mother, Self → `guardian_name`, `guardian_phone`, `guardian_email`,
  `guardian_occupation` are **derived on the server** from that person's record,
  so an auto-filled value can never drift from its source (a tampered value in
  the POST is overwritten). Because the personal fields are on the same page, the
  derivation runs against the values typed in the *same* request.
* Other → the guardian block is independently maintained and stored exactly as
  entered. Switching from a derived relation to Other keeps the values that were
  on screen (the browser submits them), so no data is lost.
* Consistency rule: choosing Father/Mother/Other without that person's name is
  refused at submission (it would store a guardian identifying nobody). With no
  relation chosen, nothing extra is demanded — this is a consistency check, not a
  newly mandatory field.

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

### Step 2 — Academic Record
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
* **Current enrollment context** — the ERP's one academic order, exactly as it
  appears on the enrollment form and on the student profile:

  | # | Field | Offered from | Depends on |
  |---|-------|--------------|------------|
  | 1 | Academic Year | `academic_years` | — |
  | 2 | Programme / Course | `programs` | — |
  | 3 | Semester / Year of study | `academic_terms` of the chosen year | academic year |
  | 4 | Section / Batch | `sections` of the chosen (year, programme) | academic year + programme |
  | 5 | Admission date | date input | — |
  | 6 | Enrollment date | date input | — (applied on approval) |
  | 7 | Admission category | read-only, captured in section B | — |

  * The semester / year of study is a **configured dropdown** for the selected
    academic year, not free text. Only while the chosen year has **no**
    configured `academic_terms` does the same slot fall back to a text input, so
    a college that has not set up its academic structure yet can still register
    students (a legacy `semester` string is then stored).
  * Changing the year clears the semester and section and reloads both lists from
    `students.registration.academic-options`; changing the programme keeps a
    semester that still belongs to the year and narrows the sections. A failed
    lookup clears both lists and says so — a stale section is never left looking
    valid.
  * The endpoint answers from `AcademicContextService`, the same service the
    write path validates with, so the browser can never offer a combination the
    server refuses; a forged/foreign id resolves to an empty list, never to
    another tenant's structure.
  * Every id is tenant-scoped. On save the server re-validates the term against
    the enrollment's own academic year and college, and the section against its
    (year, programme) pair. A term from another year or college is a field error,
    never a silent `null`.
  * When a term is chosen, the stored `semester` **label** is derived from that
    term, so the reference and the printed label can never describe different
    levels.
  * The admission category is shown read-only because it is captured once, in
    section B.

### Step 3 — Attach Documents

Three separate things, in this order — declarations, the files themselves, and
one optional row for anything else. They are never conflated:

**A. Select the documents you have (declarations).** One checkbox card per
document type, in a responsive grid (`.erp-doc-picker`). The list is built from
the configuration that already exists: `admission_document_types` (per-college
master: allowed extensions/MIMEs, max size, global `is_required`) +
the `admission_document_type_program` pivot (`is_required`, `can_submit_later`).

* An explicit pivot row wins over the global flag (so a course can make a
  globally-required document optional, or vice versa).
* `can_submit_later = true` marks a document as "may be submitted after
  admission" — it does not block submission.
* **Nothing is pre-selected or locked.** The applicant states what they have and
  can change their mind; a required type carries a `Required` chip and a
  `data-erp-doc-required` marker (used by the browser and re-checked by the
  server). Optional and deferred types are the applicant's choice.
* Every card is a clean, readable checkbox with the **full** type name (the label
  wraps, it is never truncated) plus the *file* state: `No file attached` /
  `File attached — awaiting verification` / `Verified` (a human decision made in
  the Documents module). A `Selected` chip follows the checkbox.
* Nothing is hard-coded: the picker only ever lists configured types, and an empty
  master shows a clear "no document types configured" state.

**Required type vs optional file.** A type the course requires must be
**declared** before Final Review / submission. Its **file stays optional**: the
registration can be submitted with zero files, and a scanned copy can be added
later from the student's Documents tab. The declaration is what is enforced:

| Where | What is checked |
| --- | --- |
| "Continue to review" (browser) | every `data-erp-doc-required` box is ticked — the step does not advance, the missing types are named, the group is scrolled into view and the first missing box is focused (all other data kept) |
| "Continue to review" (server) | `StudentRegistrationWizardController::persistStep()` → `CourseDocumentRequirementService::missingDeclared()`; a failure redirects back to step 3 with the same message |
| Submit (server) | `RegistrationSubmissionService::assertDocumentRequirements()` — reads the **stored declarations**, never the request, so a hand-crafted POST cannot skip a required type |

Declarations are persisted in the draft payload (`declared_documents`, a list of
document-type ids) and saved with the step — including an immediate background
save when a box changes, so attaching a file afterwards cannot lose the ticks.
"Save draft" is never blocked by a missing declaration; only continuing and
submitting are.

**B. Attach the files (optional).** One compact card per *declared* type, each
with its own upload form (file + remarks + Attach). Ticking a box reveals its
card; un-ticking hides only an *empty* card. **A card that holds a staged file is
never hidden and its file is never discarded by a toggle** — removal is the
explicit `Remove` action. Below the cards, every staged file is listed in one
table (type, file name, size, status, Replace / Remove), and several files of the
same type are allowed.

**C. "+ Attach a document"** — a collapsed `<details>` row (Document type,
optional Title, File, Attach) for a file that is not in the list above. The row
is optional; it asks for a file only when it is actually submitted, and an empty
submission is refused (server-side `file.required`, never a placeholder record).

* A declaration never uploads, selects or verifies a file, and an upload never
  silently declares its type.
* Status is always **awaiting verification** until a human verifies it in the
  Documents module.
* Files are stored privately under
  `students/{college_id}/registration-drafts/{draft_id}/{uuid}.{ext}`, streamed
  through authorised routes only, and adopted (same blob) into `student_documents`
  on submission. Existing file validation (mimes, size, tenant, authorization) is
  unchanged.

### Step 4 — Final Review
* Student Identification, Basic Information (sections B–H, including Parent and
  Guardian), Academic Record and Documents — each group with its own **Edit**
  link back to the owning step (all Basic groups edit step 1).
* Possible-duplicate hints (§4) are shown as a review note, exactly as the
  approver will see them.
* Documents are shown as **two separate lists**: *Declared documents* (what the
  applicant states they have, with its own status label) and *Attached files*
  (each awaiting verification). Neither list is derived from the other, and a
  missing required declaration is called out with a link back to step 3.
* Pre-submit validation re-runs every step in strict mode against the **stored**
  draft, plus: guardian consistency, qualification arithmetic, academic-context
  hierarchy, course-wise required documents, and identity uniqueness (against the
  decrypted numbers).
* A failure sends the applicant to the step that owns the field (step 1, 2 or 3),
  with field-level errors.
* Final remarks + a declaration checkbox. Submitting raises the pending student
  and the single approval request.

## 3. Identifiers

Three identifiers, three different lifetimes. **None of them is an input** — no
validation rule carries them and `RegistrationStepRules` drops anything outside
its field lists, so a hand-crafted POST cannot set one.

| Identifier | Column | Format | Allocated by | Wizard shows |
| --- | --- | --- | --- | --- |
| **Student ID** | `students.student_number` | `STU-{YEAR}-{SEQ4}` (`STU-2026-0001`) | `GenerateStudentNumber` when the Student row is created (submission/approval), inside a college row lock | `Pending — generated when the registration is submitted.`, then the permanent number |
| **Admission Number** | `admissions.admission_number` | `ADM-{YEAR}-{SEQ4}` | `GenerateAdmissionNumber`, when an admission is finalised (Admissions module) | the number of the admission the student came through, or `Pending — assigned when the admission is finalised. Registration does not mint one.` |
| **Roll Number** | `student_enrollments.roll_number` | plain sequence, zero-padded to 2 (`01`, `02`, … `25`) | `GenerateRollNumber`, inside `StudentService::createEnrollment()` — i.e. only once the academic year, programme **and** section are known | the number of the live enrollment, or `Pending — assigned with the class/section enrollment, after approval.` |

* **Student ID** is permanent: it is never regenerated on semester, year, class or
  section changes, never reissued on edit, and never derived from the row id.
* **Roll Number** belongs to one enrollment because it is per class/section: the
  same number may exist in another section, programme or year. Uniqueness is
  enforced per `(college, academic year, programme, section)` by
  `student_enrollments_scope_roll_number_unique` **and** by the allocator, which
  reads the used numbers under a college row lock and retries; it never relies on
  `MAX(id) + 1`. An explicit office value that clashes is a `roll_number` field
  error, never a silent overwrite.
* Opening the wizard, saving a draft and saving any step allocate **nothing**: a
  draft cannot hold an identifier.
* `StudentIdentifierService` is read-only; it resolves what already exists and
  reports the rest as pending. It never fabricates a value.

## 4. Duplicate detection

Two layers, deliberately different in strength:

| Layer | Signal | Effect |
| --- | --- | --- |
| **Blocking** — `RegistrationIdentityGuard` | the student's own **phone**, **Aadhaar** (compared through its keyed digest, never the plain number) or **APAAR / ABC ID** already held by a live student of the same college | field-level validation error; checked on a strict step-1 save, at submit and again at approval (the student is excluded from its own check) |
| **Warning** — `RegistrationDuplicateDetector` | the **name matches** (first name, and last name when given) **and at least one corroborating detail** agrees — date of birth, father's name or mother's name | a hint stored on the draft, shown on the Final Review and carried into the approval request; a human decides. Never a block |

* A matching **name alone** is never a duplicate, and neither is a name + father's
  name on its own: common names are common.
* A **shared family mobile** does not identify the applicant — `father_phone`,
  `mother_phone` and `guardian_phone` have no uniqueness rule, so two siblings
  registering with the same parent number are both accepted. The student's *own*
  `phone` keeps its existing one-live-student-per-number policy, unchanged.
* Every check is scoped to the college; the same name, phone or APAAR in another
  college is a different person.
* A rejected registration never leaves a partial record: the checks run before any
  write (including before a stored image or a draft row).
* Aadhaar and government ID numbers never appear in a URL, a log line, the
  approval payload or the client-side page state; the wizard shows only the masked
  form.

## 5. Schema added

| Migration | Purpose |
| --- | --- |
| `2026_10_28_000001_create_student_qualifications_table` | Previous/external qualifications per student (marks, grade, subjects JSON, soft deletes). |
| `2026_10_28_000002_create_registration_wizard_tables` | `student_registration_drafts`, `student_registration_draft_documents`, `geo_locations`. |
| `2026_10_28_000003_add_registration_profile_columns_to_students_table` | Parent mobiles/emails/occupations; signature + father/mother/guardian photo paths; district, block/tehsil, gram panchayat/ward; optional `geo_locations` ids; correspondence address block + same-as-permanent flag. |
| `2026_10_28_000004_add_submission_timing_to_admission_document_type_program_table` | `can_submit_later` on the existing pivot. |
| `2026_10_28_000005_add_semester_to_student_enrollments_table` | Year-of-study / semester of one enrollment. |
| `2026_10_29_000001_add_roll_number_to_student_enrollments_table` | Nullable `roll_number` + `student_enrollments_scope_roll_number_unique` (college, academic year, programme, section). |
| `2026_10_29_000002_add_duplicate_warnings_to_student_registration_drafts_table` | Nullable JSON `duplicate_warnings` (possible-duplicate hints). |
| `2026_10_29_000003_upgrade_registration_draft_payloads_to_four_steps` | Rewrites stored five-step draft payloads to the four-step layout and remaps `current_step`. Additive (no column/row removed); irreversible by design (§7). |
| `2026_10_30_000001_add_academic_term_id_to_student_enrollments_table` | Nullable `academic_term_id` reference to the configured semester / year of study. The free-text `semester` label is kept, so older rows, exports and ID cards are unaffected. |

Every column is nullable and additive; no existing column changed its meaning and
no existing identifier was renumbered.

## 6. Routes

| Method | URI | Name |
| --- | --- | --- |
| GET | `students/registration` | `students.registration.create` (starts/resumes the wizard) |
| GET | `students/registration/step/{step}` | `students.registration.step` (1–4) |
| POST | `students/registration/step/{step}` | `students.registration.step.save` (Next) |
| POST | `students/registration/step/{step}/draft` | `students.registration.step.draft` (Save draft) |
| POST | `students/registration/documents` | `students.registration.documents.upload` |
| POST | `students/registration/documents/{document}` | `students.registration.documents.replace` |
| POST | `students/registration/documents/{document}/delete` | `students.registration.documents.destroy` |
| GET | `students/registration/documents/{document}/download` | `students.registration.documents.download` |
| GET | `students/registration/image/{field}` | `students.registration.image` (draft photo preview) |
| GET | `students/registration/geo/children` | `students.registration.geo.children` |
| GET | `students/registration/academic-options` | `students.registration.academic-options` (dependent semester + section lists; ids and labels only) |
| POST | `students/registration/submit` | `students.registration.submit` |
| POST | `students/registration/cancel` | `students.registration.cancel` |

A step number outside 1–4 is a 404 on both the read and the save routes.

`POST students/registration` (`students.registration.store`) is **kept** for
existing callers: it runs the same `StudentRegistrationService` and the same
approval flow.

## 7. Old (five-step) drafts

A stored payload cannot simply be read with the new step numbers: step 2 used to
hold parent/guardian data and now holds the academic record. Two mechanisms use
**one** mapping, defined in `App\Domain\Student\Support\RegistrationDraftUpgrade`:

| Old step | New step |
| --- | --- |
| 1 Basic | 1 Basic (keeps its keys) |
| 2 Parent/Guardian | 1 Basic (merged in — the key sets are disjoint) |
| 3 Academic | 2 Academic |
| 4 Documents | 3 Documents |
| 5 Review | 4 Review |

`current_step` follows the same map **for a legacy payload only**, so an
applicant parked on the old Parent/Guardian page reopens Basic Information —
where their parent data now lives — instead of landing on the Academic Record by
accident. A draft already in the four-step shape keeps its own step number
(clamped to 1–4): running a current step through the legacy map would drag step 2
back to step 1 and step 4 back to step 3, i.e. resume the wrong page.

* **At read time** (`StudentRegistrationDraft::upgradedPayload()`,
  `currentStep()`) a legacy payload is translated in memory, so an old draft opens
  correctly even before the migration has run.
* **At rest** migration `2026_10_29_000003` rewrites the stored JSON and the step
  marker, chunked and idempotent, and is deliberately irreversible: re-splitting a
  merged step could no longer tell basic fields from parent fields.
* Detection is content-based (`key 5` present, `step 2` holding parent fields, or
  `step 3` holding academic fields) — never a blind "shift by one". The review
  step is *not* used as a signal, because `remarks` is written by the new step 4
  as well.
* Staged document rows and their private files are untouched (they are rows, not
  payload), and ownership (`college_id` + `user_id`) is unchanged.

## 8. Security notes

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

## 9. Known limitations / decisions

* **Districts, blocks and villages are not shipped.** The seeder loads India and
  its 36 states / union territories from `database/data/geo_locations.json`; the
  deeper levels must be imported from the college's own administrative source
  (`GEO_LOCATION_SOURCE`, JSON tree — see the seeder header). Until then those
  levels stay free-text and the page says so. No place name is invented.
* **No qualification is mandatory.** The canonical Student rules never required
  previous education, so the wizard does not demand a qualification row; rows
  that exist are fully validated. If a college requires at least one, that is a
  business rule to confirm before it is enforced.
* **Semester follows the academic year, not the programme.** `academic_terms` is
  scoped to a college and an academic year; the schema has no per-programme term
  configuration. The dropdown therefore lists the levels of the selected year.
  A programme→term table would be master data the office has never entered, so it
  was not invented. Until a year has configured terms, the same field falls back
  to the free-text `semester` string, and older rows keep that label.
* **Document types are not seeded.** The wizard shows "no document types
  configured" and treats everything as optional until Admission → Document Types
  is populated.
* **No identifier is mandatory at registration.** All three are read-only and may
  legitimately be pending: a student can register (and be approved) without an
  admission number (direct admission) or a roll number (no section chosen yet).
* **Roll numbers are allocated, not typed, at approval.** An office that numbers
  its own registers can supply a value on the enrollment screen; a clash is
  refused with a field error rather than overwritten.

## 10. The unified academic workflow

One sequence is used everywhere — registration wizard, New/Edit Enrollment, the
student profile, the student list filter and the reports:

    Academic Year → Programme / Course → Semester / Year of study → Section / Batch

What the schema actually models, and therefore what every screen offers:

| Level | Table | Scoped to |
|-------|-------|-----------|
| Academic year | `academic_years` | college |
| Programme | `programs` | college |
| Semester / term | `academic_terms` | college **and** academic year |
| Section / batch | `sections` | college, academic year and programme |

There is no programme → term configuration in the database, so the semester list
follows the academic year. That is a documented limitation, not a shortcut:
inventing a programme→term table would be master data the office has never
entered.

`student_enrollments.academic_term_id` (additive, nullable) is the reference;
the existing `semester` string keeps the human label so exports, ID cards and
older records keep working. `StudentEnrollment::semesterLabel()` is the single
place that decides what to print: the configured term's name when present, else
the stored label, else "not set".

### Current vs historical enrollment

`Student::currentEnrollment()` does **not** return the newest row. It considers
only `active` enrollments, prefers the one whose academic year contains today,
and falls back to the latest active row when no year contains today (a college
that has not opened the next session yet). `previousEnrollments()` and the
profile's enrollment history keep every other row, newest academic year first,
flagged current/historical — a promotion or a new admission adds a row and never
rewrites or deletes an earlier one.

### Student profile

`students.show` is the single profile page (no second profile master, no new
route):

* **Overview** — registration/admission facts and the current academic position.
* **Personal Information** — the fields the wizard writes, including both
  addresses, parent/guardian, and the masked identity numbers.
* **Academic Information** — the current enrollment's year, programme, semester,
  section, numbers, dates, category and both statuses.
* **Qualifications** — every saved row with level, exam year, board, institution,
  roll/registration numbers, marks, percentage (only when legitimately derivable),
  grade/grade letter, result, subjects and remarks.
* **Documents** — type, file, upload date and the stored verification status, with
  an inline preview for images through the authorized `student-documents.preview`
  action and a download link otherwise. No storage path is ever rendered.
* **Enrollment History** — every enrollment, newest first, current row marked.

The five lifecycle modules that own their own screens (Academic Records, ID Card,
Promotion, Transfer/TC, Lifecycle Log) stay reachable from a quieter second row of
links, using their original `?tab=` keys, so no functionality was removed by the
reorganisation.

Data is assembled once by `App\Domain\Student\Services\StudentProfileService`;
the view only prints it, which is why the header, the summary cards and the
academic panel cannot disagree. The photograph reuses the existing
`students.photo_path` column and is streamed by `students.photo`; when it is
absent — or the file cannot be read — the initials fallback shows and the `<img>`
removes itself, so a broken-image icon is never displayed.

## 11. Tests

`tests/Feature/Students/RegistrationWizardTest.php` covers navigation and step
validation, draft persistence/resumption, the dependent address chain,
same-as-permanent correspondence, guardian auto-fill (Father/Mother/Self/Other) on
the merged step 1, multiple qualifications with marks arithmetic and grade
preservation, course-wise required/deferred documents, upload authorization
(foreign college type) and privacy, submission through the approval framework,
duplicate submission prevention, tenancy isolation and permission checks, plus:

* the eight step-1 sections in order, with Section A = Student Identification and
  Section B = Personal Details, no numeric prefixes and no identifier input;
* identifiers: nothing allocated by opening/saving, the Student ID generated once
  and never changed, roll numbers unique per section and refused on a clash (at
  the service *and* at the database index);
* duplicate policy: a name alone is not a duplicate, a name + father's name is a
  warning and never a block, warnings are tenant-scoped, a shared parent mobile is
  allowed while the student's own number still blocks, and a duplicate APAAR
  blocks without writing anything;
* four-step structure (exactly four steps, a fifth is a 404) and the old-draft
  mapping, including a current four-step payload that must **not** be re-mapped.;
* step-3 declarations vs files: the required type is identified in the UI and
  nothing is pre-selected or locked, an unselected required type blocks "Continue
  to review" (the message names it) and the server refuses the same step, a
  submission whose stored declarations are incomplete is refused, a declared type
  submits with **no file at all**, an optional type may stay undeclared, a file
  with a foreign type and a form with no file are both refused, a real attachment
  is listed as pending, draft save/resume keeps declarations *and* files,
  un-selecting a type deletes no file, a repeated save duplicates nothing, and
  declaring never verifies anything.

`tests/Feature/Layout/RegistrationNavigationTest.php` pins the sidebar: the
Registration row opens the wizard, the Student List row keeps its own
destination, exactly one of them is highlighted (including on all four wizard
steps), there is only one Registration row, and the fifth step no longer exists.

`tests/JavaScript/registration-wizard.test.js` covers the client-side
conveniences: the address cascade clearing children and reporting an empty
directory, guardian auto-fill (including the hidden mirrors that keep a "Self"
guardian in step with the personal fields on the same page), marks/percentage
arithmetic, adding and removing qualification rows, the document picker
showing/hiding upload cards while never hiding a card that holds a file, and the
dependent academic levels (a year change reloading the terms and clearing stale
dependents, a programme change keeping a still-valid term and narrowing the
sections, and a failed lookup clearing both lists).

`tests/Feature/Students/RegistrationWizardTest.php` pins the workflow order and
the dropdown source (field order, a configured term list replacing the free-text
fallback, the options endpoint returning only the selected year/college, a term
from another year or another college rejected on save) and asserts that approval
carries the configured term into the created enrollment.
`tests/Feature/Students/StudentEnrollmentAcademicContextTest.php` covers the same
rules on New/Edit Enrollment, and
`tests/Feature/Students/StudentProfilePresentationTest.php` plus
`StudentProfileTabsTest.php` cover the profile itself (initials fallback vs
streamed photograph, current-vs-historical by academic period, grade-based
qualifications never gaining a percentage, documents via authorized routes with
their stored verification status, personal data, and the six sections).

`tests/Feature/Students/StudentRegistrationApprovalTest.php` was updated for the
new entry point (the wizard) and continues to cover the single-POST flow,
approval effects and pending-student visibility.
