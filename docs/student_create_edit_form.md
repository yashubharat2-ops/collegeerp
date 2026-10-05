# Student Create/Edit Form

The single Student Create/Edit form, delivered as the six numbered sections the
brief asks for — Academic / admission first, Documents last, with the
government IDs as a **sub-block of Basic information** rather than a section of
their own:

| # | Section | Fields | Storage |
| --- | --- | --- | --- |
| 01 | Academic / Admission | student number, admission source, current enrollment, department (all read-only on edit), then **optional first enrollment** (academic year / program / section), admission date, status, enrollment date (the first enrollment's), and the previous-education snapshot — in that chronological order | existing columns + `StudentEnrollment` (existing module) + new previous-education columns |
| 02 | Basic Information | first / middle / last name, gender, date of birth, category, blood group, nationality, **photograph**, and the *Government / identity* sub-block: Aadhaar number, APAAR / ABC ID, other government ID (type + number) | existing `students` columns + existing `photo_path` (private disk) + new nullable `students` columns; Aadhaar + other ID number **encrypted** |
| 03 | Parent / Guardian | father's name, mother's name, guardian name, relationship, phone, e-mail, occupation, address | new nullable `students` columns |
| 04 | Contact | e-mail, phone, alternate phone, emergency contact name/phone, address line 1/2, city, state, postal code, country | existing columns + new emergency-contact columns |
| 05 | Additional Information | mother tongue, remarks | new nullable `students` columns |
| 06 | Documents | link to the student's documents and to the upload form (no inline upload) | existing `student_documents` module |

Only `first_name` and `status` are required (the first name sits in section 02
and the student status in section 01); every other field may be completed later
from the same form. Bulk student registration is
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
- `public/css/erp-student-form.css` (the whole form vocabulary: `.erp-card`,
  `.erp-grid`/`.erp-col-*`, `.erp-input`, `.erp-photo-block`, …)
- `tests/Feature/Students/StudentProfileFormTest.php`,
  `tests/Unit/Students/AadhaarTest.php`

## Layout — six numbered section cards on a 12-column grid (static stylesheet, no build needed)

The form is a dense ERP data-entry screen, not a stacked registration page.
`create.blade.php` and `edit.blade.php` are structurally identical: each renders
the page header, then the one partial inside `erp-student-page`:

```blade
<div class="erp-student-page">
    <header class="erp-page-header"> New Student · Create a new student record </header>
    <form method="POST" enctype="multipart/form-data">
        @include('students._form', ['submitLabel' => 'Create Student'])
    </form>
</div>
```

The partial renders the six `<section class="erp-card">` containers itself, so a
field can never appear outside a section, and each card carries its number
(`01`–`06`), a 14.5px semibold title and a single muted description line.

| # | Section | What it holds |
| --- | --- | --- |
| 01 | Academic / admission | *(create)* optional first enrollment: academic year · program/course · section/batch, then admission date · student status, then its enrollment date, then previous education — the controls follow admission chronology. *(edit)* a read-only `.erp-strip` (student number, admission application, current enrollment, department) plus `Manage enrollments` |
| 02 | Basic information | first · middle · last name, date of birth · gender · blood group · category, nationality — plus the compact photograph block — and the **Government / identity sub-card**: Aadhaar · APAAR/ABC, ID type · ID number |
| 03 | Parent / guardian | father + mother · guardian name + relationship + phone · guardian email + occupation · guardian address (full) |
| 04 | Contact | email + mobile + alternate mobile · address line 1 (9) + postal code (3) · address line 2 (full) · village/city + state + country · emergency contact name + phone |
| 05 | Additional information | mother tongue · remarks (full) |
| 06 | Documents | one compact action row into the Document module — no second upload system |

**Government IDs are not a section.** They live in the `.erp-subcard` inside
Basic information (a hairline block with a tinted left edge), because they
describe the same person and only take two rows. Nothing was renamed, merged or
hidden to reach this arrangement: every control and every validation message of
the previous markup is still present, exactly once — the "no duplicate academics
masters" rule is kept too, since Department is derived read-only from
`StudentEnrollment.program.department` and the Section list is narrowed
client-side to the chosen year/program and re-validated server-side.

Order is enforced by the feature test, not only by this table:
`test_the_create_form_renders_every_requested_section_in_order` asserts the
headings appear once each, in chronological order, and that
`Government / identity` sits *between* Basic information and Parent / guardian —
so a future refactor that reintroduces a separate identity section fails.
`test_the_academic_section_renders_its_fields_in_chronological_order` holds the
01 controls to the admission chronology on both screens (academic year ·
program/course · section/batch · admission date · student status · enrollment
date · previous education) — so re-sorting that card into, say, enrollment date
first fails too. Both assertions read the rendered **form** markup
(`studentFormHtml()`), not the whole response: the sidebar draws permission-free
`Documents` module rows above `<main>`, so a page-wide search would match that
navigation instead of the form's own Documents card.

**Why a static stylesheet instead of Tailwind utilities.** The layout only emits
`@vite(...)` when `public/build/manifest.json` or `public/hot` exists:

```blade
@if (is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@endif
```

With no build (and no dev server) Tailwind never reaches the browser, so a grid
written as `md:grid-cols-12` / `md:col-span-4` / `py-1.5` collapses to a plain
one-column document with unstyled inputs — the class names are simply inert.
The form is therefore styled by **`public/css/erp-student-form.css`**, linked
directly by `resources/views/layouts/app.blade.php` with the same static-asset
pattern (and `filemtime` cache-buster) as `erp-sidebar.css`, `erp-user-menu.css`,
`erp-dropdown.css` and `erp-list.css`, after the Vite block so a stale bundle can
never win. Its `.erp-*` names are unique to the student form, so no other screen
is affected.

- **12-column grid.** Each card body holds one `.erp-grid`
  (`repeat(12, minmax(0, 1fr))`; `gap: 12px`). A field declares its width with
  `.erp-col-3` (quarter), `-4` (third), `-6` (half), `-8` (two-thirds), `-9` or
  `-12`, so three to four short fields share a row and every row sums to exactly
  12 columns:

  | Section | Rows (desktop, as resolved from the shipped CSS) |
  | --- | --- |
  | Academic / admission | `12` subhead · `3+3+3` year · program · section · `3+3` admission date · student status · `3` enrollment date · `12` subhead · `6+6` school · board · `4+4+4` qualification · year · marks |
  | Basic information | `4+4+4` first · middle · last name (beside a 108px photo column) · `3+3+3+3` date of birth · gender · blood group · category · `4` nationality · `6+6` Aadhaar · APAAR/ABC · `4+8` ID type · ID number |
  | Parent / guardian | `6+6` father · mother · `4+4+4` guardian name · relationship · phone · `6+6` guardian email · occupation · `12` guardian address |
  | Contact | `4+4+4` email · mobile · alternate mobile · `9+3` address line 1 · postal code · `12` address line 2 · `4+4+4` village/city · state · country · `6+6` emergency name · phone |
  | Additional information | `4` mother tongue · `12` remarks |
  | Documents | one compact action row (student number + `[Open Document Register]` + upload link) |

  That is 22 field rows on the create page (20 on edit, which has no first
  enrollment), so the whole form is a couple of screens instead of a long list.
- **The photograph is a block, not a column.** `.erp-basic` is
  `minmax(0, 1fr) 108px` — the fields take everything else — and the block itself
  is 108px wide with a 92×112px portrait/placeholder, a `Browse…` label bound to
  the native `input type="file" name="photo"` (which stays in the DOM and
  focusable, so the picker opens with no JavaScript), a one-line 2 MB
  jpg/png/webp hint and the remove checkbox. `object-fit: cover` keeps any
  portrait ratio from stretching the block.
- **Cards.** `1px solid #dfe6ef` on white, `8px` radius, a `#f8fafc` header strip
  with the number chip and title, `16px` between sections, on a light slate page
  surface — so the six sections read as six groups at a glance. Padding is
  compact (`8px 12px` header, `8px 12px 12px` body).
- **Dates are DD/MM/YYYY on screen, YYYY-MM-DD on the wire.** A native
  `<input type="date">` paints its segments in the *browser's* locale order
  (month/day/year on an en-US browser) and that order cannot be restyled —
  `lang="en-IN"` is only a hint, and the `::-webkit-datetime-edit-*` fields
  cannot be regrouped with their separators. So every date field is a two-layer
  shim (`.erp-date`): a visible, read-only `.erp-date__text` mirror carrying
  `DD/MM/YYYY` (placeholder `DD/MM/YYYY` while empty), beneath the real
  `<input type="date">` — unchanged `id`/`name`/value/`lang`/validation — which
  sits on top, transparent, still owning the picker and still posting ISO.
  Clicking anywhere in the field opens the calendar (`showPicker()`), and a
  ~20-line inline script keeps the mirror in step while typing or picking. The
  server renders the mirror from the same value the control carries, so the two
  can never disagree; the display helper is string-only and exception-free, so a
  crafted `old()` value shows the validation message instead of breaking the
  re-render. The submitted value, the validation rules and the existing `now()`
  defaults are untouched.
- **Mobile/tablet.** A `max-width: 899px` query makes every column full width
  (one column) and turns the photograph block into a compact horizontal row; a
  `640–899px` query uses two columns and keeps the long fields (`-8`, `-9`, `-12`)
  full width. `minmax(0, 1fr)` tracks plus `min-width: 0` on `.erp-field` mean a
  long `<select>` option can never widen a row into horizontal overflow.
- **Compact chrome.** Labels are 12px semibold with a 4px gap; inputs are 36px
  tall with a 1px `#cbd5e1` border, white background, 6px radius and an indigo
  focus ring; `.erp-error:empty` collapses so a valid field reserves no error
  height, and `:has()` tints the offending control (a graceful no-op where
  `:has()` is unsupported).
- **No hidden fields, no tabs/accordions**: all six sections render on one page.

## Tests

`StudentProfileFormTest` covers the six sections (their order, the chronological
field order inside 01, and that Government / identity is a sub-block rather than a
section), persistence of every field,
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
