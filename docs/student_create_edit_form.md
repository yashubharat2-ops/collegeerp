# Student Create/Edit Form

The single Student Create/Edit form, delivered as the seven sections the brief
asks for:

| Section | Fields | Storage |
| --- | --- | --- |
| Basic Information | first / middle / last name, gender, date of birth, category, status, **photograph** | existing `students` columns + existing `photo_path` (private disk) |
| Parent / Guardian | father's name, mother's name, guardian name, relationship, phone, e-mail, occupation, address | new nullable `students` columns |
| Identity & Government IDs | Aadhaar number, APAAR / ABC ID, other government ID (type + number) | new nullable `students` columns; Aadhaar + other ID number **encrypted** |
| Contact | e-mail, phone, alternate phone, emergency contact name/phone, address line 1/2, city, state, postal code, country | existing columns + new emergency-contact columns |
| Academic / Admission | student number (read-only), admission source (read-only), current enrollment (read-only), admission date, **optional first enrollment** (academic year / program / section / date), previous-education snapshot | existing columns + `StudentEnrollment` (existing module) + new previous-education columns |
| Additional Information | blood group, nationality, mother tongue, remarks | new nullable `students` columns |
| Documents | link to the student's documents and to the upload form (no inline upload) | existing `student_documents` module |

Only the basic information is required (`first_name`, `status`); every other
section may be completed later from the same form. Bulk student registration is
deliberately **not** part of this milestone.

## Reused instead of reinvented

- **The Student record**: no parallel "profile" entity, table or route was
  introduced. The form writes the existing `students` row, and the 360° profile
  (`students.show`, pre-existing) renders what it wrote.
- **`photo_path`** already existed on `students`; the photo upload simply fills
  it (through `StudentPhotoService`, on the private disk).
- **Academic years / programs / sections / enrollments**: the optional first
  enrollment is created by the existing `StudentService::createEnrollment()`
  (same tenant-scoped checks, same college-row-locked `ENR-…` number generator,
  same duplicate-active protection, same audit entry).
- **Student documents** (`student_documents`, `AdmissionDocumentType`): only
  linked, never duplicated.
- **RBAC, policies, audit log, tenant context, private file service**: all
  unchanged and still the only write path.

## New columns (one migration: `2026_10_04_000002_add_profile_and_identity_fields_to_students_table`)

All nullable, no defaults, nothing existing modified — existing rows and the
existing Student/Enrollment/Document/Report flows keep working untouched.

- **Parent/guardian**: `father_name`, `mother_name`, `guardian_name`,
  `guardian_relation`, `guardian_phone`, `guardian_email`,
  `guardian_occupation`, `guardian_address`.
- **Identity**: `aadhaar_number` (ciphertext), `aadhaar_last4`,
  `aadhaar_hash`, `apaar_id`, `govt_id_type`, `govt_id_number` (ciphertext).
- **Contact**: `emergency_contact_name`, `emergency_contact_phone`.
- **Academic/admission snapshot**: `previous_school_name`,
  `previous_school_board`, `previous_qualification`, `previous_exam_year`,
  `previous_percentage`.
- **Additional information**: `blood_group`, `nationality`, `mother_tongue`,
  `remarks`.

### Why columns and not a `student_guardians` table

The foundation design names a guardian table as a *future extension point*
(§9). This milestone captures exactly one primary parent/guardian block per
student, which is an attribute of the person: a normalised table would need its
own CRUD, policy, permissions and portal before it earns its keep, and would
turn one save into a multi-write workflow. The column set keeps the single
atomic write, one audit entry and no new authorization surface. When multiple
guardians (or guardian accounts) are genuinely needed, that is a separate
milestone with its own migration — the columns added here map onto it one-to-one.

## Aadhaar and identity security

- **Encrypted at rest.** `aadhaar_number` and `govt_id_number` are stored as
  `Crypt` ciphertext (AES-256 with the application key) via model accessors
  (`Student::aadhaarNumber()`, `Student::govtIdNumber()`). A blank value is a
  real `NULL`, never an encryption of `""`.
- **Masked in every response.** Only `aadhaar_last4` is stored in clear text, so
  the UI can print `XXXX XXXX 1234` without decrypting anything. The edit form
  renders the masked value and an *empty* input: the full number is **never**
  echoed into the HTML — not even after a failed validation — and it is never
  exported or audited.
- **Hidden from serialisation.** `aadhaar_number`, `aadhaar_hash` and
  `govt_id_number` are in the model's `$hidden`, so no `toArray()`/`toJson()`
  (API resource, log line, accidental dump) can leak them.
- **Duplicate detection without decryption.** `aadhaar_hash` is a deterministic
  HMAC-SHA256 of the 12 digits keyed with the application key, indexed per
  college. Correlating rows therefore never decrypts anything, and a database
  dump cannot be brute-forced over the 12-digit space without the key. The index
  is **not** unique (the table is soft-deletable, exactly like the student
  number): uniqueness is enforced for LIVE rows at the validation boundary,
  per college — the lookup never reads another tenant's rows.
- **Checksum-validated input.** `App\Domain\Student\Rules\ValidAadhaar` accepts
  the 12-digit format (spaces/hyphens are stripped) and verifies the Verhoeff
  checksum of the twelfth digit, the check UIDAI itself uses, so a mistyped
  number is rejected at the form boundary.
- **Edit semantics.** A blank field keeps the stored value; a supplied number
  replaces it (re-encrypted, tail and digest rebuilt server-side); the explicit
  `remove_aadhaar` / `remove_govt_id` / `remove_photo` flags clear it. The
  government ID is a type + number pair, so submitting no type ("— None —") also
  clears the pair — and the number field is only *required* while a type is
  being chosen for the first time, never on a plain re-save. The browser can
  never set `aadhaar_last4`, `aadhaar_hash` or `photo_path` — they are stripped
  in `prepareForValidation()` and derived in `StudentService`.

## Tenancy, policies and validation

Unchanged and re-asserted by tests:

- Every new field is a plain column on the tenant-scoped `students` table;
  `CollegeScope` still decides what exists, and the form request derives the
  tenant from `TenantContext` (a browser-supplied `college_id` is stripped).
- `students.create` / `students.update` still gate the form (Form Request
  `authorize()`), and a cross-college student still 404s.
- The optional first enrollment is only accepted from a user who may
  `create` a `StudentEnrollment`; for anyone else the fields are stripped from
  the request *and* the block is not rendered — the Student policy is never a
  way around the Enrollment policy. Every year/program/section id is
  tenant-scoped (`Rule::exists(...)->where('college_id', …)`), and the
  year/program/section combination is re-validated in `StudentService` inside the
  same transaction, so a rejected combination rolls the student back too.
- The activity is audited with the existing actions (`student.created`,
  `student.updated`, plus `student_enrollment.created` when an enrollment is
  created) using an allow-list that includes the masked tail and excludes every
  full identity number.
- Student numbers, admission provenance and row identity remain server-owned and
  immutable — the previous behaviour is preserved (and tested).

## Files

- `database/migrations/2026_10_04_000002_add_profile_and_identity_fields_to_students_table.php`
- `app/Domain/Student/Support/Aadhaar.php`, `…/Support/SensitiveIdentity.php`
- `app/Domain/Student/Rules/ValidAadhaar.php`
- `app/Domain/Student/Services/StudentPhotoService.php`
- `app/Domain/Student/Services/StudentService.php` (payload normalisation,
  optional first enrollment, photo lifecycle)
- `app/Http/Requests/Student/StoreStudentRequest.php`,
  `…/UpdateStudentRequest.php`, `…/Concerns/HandlesStudentProfile.php`
- `app/Models/Student.php` (columns, vocabularies, masking helpers)
- `app/Services/Files/SecureFileService.php` (`storeAs`, `inline`)
- `resources/views/students/_form.blade.php`, `create.blade.php`,
  `edit.blade.php`, `show.blade.php`
- `resources/css/app.css` (the compact `.form-*` / `button--sm` vocabulary)
- `tests/Feature/Students/StudentProfileFormTest.php`,
  `tests/Unit/Students/AadhaarTest.php`

## Layout — a compact data-entry grid

The form is a dense ERP data-entry screen, not a stacked registration page, so
`create.blade.php` and `edit.blade.php` render the one partial inside the same
`panel max-w-6xl p-4 md:p-5` wrapper and are laid out identically.

- **12-column grid.** Every section uses `.form-grid` (`grid-cols-1` below the
  `md` breakpoint, `md:grid-cols-12` above it). A field declares its width with
  `.form-field` (half), `--third`, `--wide` (two-thirds) or `--full`, so rows are
  composed instead of stacked: first / middle / last name sit on one row,
  date of birth + gender + category on the next, status beside the portrait;
  address line 1 shares its row with the postal code; city / state / country fill
  one row. Every row sums to exactly 12 columns.
- **Mobile.** No breakpoint work is needed per field: the single-column
  mobile grid plus the `md:`-only spans collapse the form to one column
  automatically, and long values wrap rather than overflow (`min-w-0`).
- **Compact chrome.** Section headings are a `text-sm` title with the hint
  inline on the same line and a hairline rule instead of a card or a banner;
  labels are `text-xs` with a 4px gap; inputs are `py-1.5` (≈30px) with a 12px
  row gap; `.form-error:empty` collapses so a valid field reserves no error
  height. Read-only context (student number, admission source, current
  enrollment) is a single `.form-strip` line, and the portrait is a 56px avatar
  in the basic-information row.
- **Full width reserved for long content**: address lines, guardian address and
  remarks — and the section picker of the optional first enrollment.
- **No hidden fields, no tabs/accordions**: all seven sections render in one
  page, all 48 controls (and all 45 error slots) are the same ones the previous
  markup rendered, only their arrangement changed.
- Buttons use the shared `.button` with a new `--sm` / `--secondary` modifier
  instead of the legacy `!utility` prefix classes, which are inert under
  Tailwind v4's suffix syntax.

## Tests

`StudentProfileFormTest` covers the seven sections, persistence of every field,
encryption at rest + derived columns, "the full number is never rendered",
serialisation hiding, masked-tail-only auditing, the Verhoeff rejection, the
per-college duplicate rule (and its tenant-scoping), soft-delete behaviour, the
edit keep/replace/remove semantics, photo storage/replacement/removal plus the
inline streaming contract, the validation of every new field, the optional first
enrollment (including the permission gate and the rollback on a mismatched
section), and the unchanged tenant/immutability guarantees.

`AadhaarTest` (unit) covers normalisation, the Verhoeff checksum, masking and
the validation rule.

> Note: the photo read path now streams **inline** instead of as an attachment —
> an `<img src>` cannot render a `Content-Disposition: attachment` response, so
> the pre-existing portrait on the profile and the ID card now actually displays.
> Document downloads keep the attachment behaviour.
