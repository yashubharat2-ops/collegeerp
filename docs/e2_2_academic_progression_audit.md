# Phase E2.2 — Academic Progression Policy Audit and Implementation Plan

**Status:** audit and planning only. No application code, test, route, permission, view
or migration was changed to produce this document.

**Repository state when the audit started and ended**

| Item | Value |
| --- | --- |
| Branch | `arena/3a7500c6-collegeerp` |
| HEAD | `3d314f6256fb677b1e89bb3adcd4b6e07d8d5dc0` |
| Commits created | 0 |
| Pre-existing files modified | 0 |
| Working tree before | 7 modified files, `+926 / −24` (E2.1-A + E2.1-B, uncommitted) |
| Working tree after | the same 7 modified files, `+926 / −24`, plus this new untracked document |
| `git diff --check` | clean |
| Stash | empty |

E2.1-A and E2.1-B are intact: `SECTION_REQUIRES_PROGRAM` (3 hits in `StudentService`,
1 in `PromoteStudent`), `hasIssuedTc` (2 hits) and `pendingPromotions` (4 hits) were all
re-grepped before and after.

**Runtime note:** there is no `php` binary in this environment and `vendor/` is absent,
so nothing in this document was verified by executing code. Every claim below was
established by reading source. Statements about behaviour are static readings, not test
results.

---

## 1. Promotion eligibility

### 1.1 The status vocabularies

`app/Models/Student.php:34`

```php
public const STATUSES = ['active', 'inactive', 'graduated', 'suspended', 'withdrawn'];
```

`app/Models/StudentEnrollment.php:28`

```php
public const STATUSES = ['active', 'completed', 'cancelled', 'withdrawn'];
```

### 1.2 What the guard actually rejects

`PromoteStudent::assertPromotable()` — `app/Domain/Student/Actions/PromoteStudent.php:393`
— is the only eligibility gate, and it rejects exactly three things:

| Check | Line |
| --- | --- |
| an issued Transfer Certificate for the student | `:395` |
| `$student->status === 'withdrawn'` | `:407` |
| `$source->status === 'withdrawn'` | `:413` |

Everything else passes. Both callers are `request():87` and `approve():212`.

### 1.3 Resulting eligibility matrix

| Student status | Promotable? | Basis |
| --- | --- | --- |
| `active` | yes | intended |
| `inactive` | **yes** | not rejected |
| `graduated` | **yes** | not rejected |
| `suspended` | **yes** | not rejected |
| `withdrawn` | no | `:407` |

| Source enrollment status | Promotable? | What approval does |
| --- | --- | --- |
| `active` | yes | source flipped to `completed` (`:253`) |
| `completed` | **yes** | source left untouched |
| `cancelled` | **yes** | source left untouched, new `active` enrollment created |
| `withdrawn` | no | `:413` |

### 1.4 Request versus approval consistency

**No inconsistency remains.** Since E2.1-A both `request()` and `approve()` call the same
`assertPromotable()`, so a request that became ineligible after being recorded is refused
at approval with the identical message. This was the E2.1-A objective and it holds.

The one asymmetry left is not in the guard but in the *write*: `approve():253` completes
the source only `if ($source->status === 'active')`. A `completed` or `cancelled` source is
deliberately never rewritten — the reason is documented in the comment at `:246-252` and
is sound. But it means the same approval can execute two structurally different things
depending on the source status, which is invisible to the operator at request time.

---

## 2. Academic year progression

### 2.1 The only progression rule in the codebase

`PromoteStudent.php:94-98`

```php
if ((int) $targetYear->id === (int) $source->academic_year_id) {
    throw ValidationException::withMessages([
        'target_academic_year_id' => 'The target academic year must be different from the source enrollment\'s academic year.',
    ]);
}
```

That is the entire rule. Answers to the investigation questions:

- **Can a student be promoted to an earlier academic year?** Yes. Nothing compares
  `starts_on`, `code` or `sequence`. Promoting from 2027-28 into 2025-26 is accepted.
- **Can academic years be skipped?** Yes. 2025-26 → 2028-29 is accepted.
- **Is the target required to be the next chronological year?** No.
- **Is the target required to be the *active* year?** No. `AcademicYear` is resolved at
  `:91` only for existence inside the tenant.

### 2.2 How the active academic year is selected and managed

`academic_years` columns (`database/migrations/2026_09_05_000003_create_academic_years_table.php`):
`name`, `code`, `starts_on`, `ends_on`, `status` (default `'inactive'`), soft deletes,
`unique(college_id, code)`, plus a DB-level `ends_on > starts_on` CHECK on
MySQL/MariaDB/Postgres/SQL Server and a matching model-level `saving` hook in
`AcademicYear::booted()`.

`AcademicYearController` has **only two methods** — `index()` and `store()` — and
`routes/web.php:210-211` exposes only `GET /academic-years` and `POST /academic-years`.
Inside `store()`:

```php
// AcademicYearController.php:27-34
DB::transaction(function () use ($data, $collegeId): AcademicYear {
    College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    $overlap = /* no date overlap for this college */;
    if ($overlap) throw ValidationException::withMessages([...]);
    if ($data['status'] === 'active')
        AcademicYear::where('college_id', $collegeId)->where('status', 'active')->update(['status' => 'inactive']);
    return AcademicYear::create(...);
});
```

So the single-active-year invariant is maintained **only at creation time**, under the
College row lock, which is the correct E1.3 convention.

**Consequence (confirmed defect C-2):** because there is no `update` route or controller
method anywhere, an academic year's `status` can never be changed after creation.

- A year created with the default `status = 'inactive'` can never become active.
- Once a newer year is created as `active`, the previous year is deactivated permanently
  and cannot be reactivated.
- Promotion does not consult the active year at all: `StudentPromotionController::create()`
  offers `AcademicYear::query()->orderByDesc('starts_on')->get(['id','name','code'])` —
  every year, active or not, past or future.

### 2.3 Overlap protection

`store()` rejects date-overlapping years per college. Combined with `unique(college_id, code)`
this means years form a non-overlapping timeline, which is a sound basis for any future
"next year" rule — the ordering information already exists via `starts_on`.

---

## 3. Semester / term handling

### 3.1 Which model the ERP actually supports

**The enrollment layer is strictly year-based.** `student_enrollments` has exactly two
migrations — `2026_09_18_000002_create_student_enrollments_table.php` and
`2026_09_19_000006_add_section_id_to_student_enrollments_table.php` — and neither declares
a term column. An exhaustive grep for `academic_term_id` across `app/` and
`database/migrations` returns hits only on these tables:

`faculty_subject_assignments`, `student_academic_records`, `student_promotions`
(`target_academic_term_id` only), `academic_subject_enrollments`, `academic_timetables`,
`academic_attendances`, `academic_calendar_events`, `examinations`, `exam_schedules`,
`exam_results`, `fee_structures`.

**`student_enrollments` is not among them.** `StudentListService` documents this
explicitly:

```php
// app/Domain/Student/Services/StudentListService.php:78-84
/**
 * A term is NOT a column of StudentEnrollment: terms live on
 * StudentAcademicRecord (the progression ledger), where the enrollment link
 * is optional. ...
 */
public const TERM_FILTER = 'academic_term_id';
```

### 3.2 Is the selected term persisted?

Partially, and this is the central finding of the audit.

| Step | Line | Term persisted? |
| --- | --- | --- |
| request → validated against target year | `resolveTerm()` `:423` | — |
| request → written | `:105`, `:142` | **yes**, on `student_promotions.target_academic_term_id` |
| approve → re-validated | `:224` | — |
| approve → written back to promotion | `:262` | yes |
| approve → `createEnrollment()` payload | `:231-244` | **no** — the payload carries only `student_id`, `academic_year_id`, `program_id`, `section_id`, `enrollment_date`, `status`, `remarks` |

**Confirmed defect C-1:** the term chosen for a promotion is validated, stored on the
decision, audited, and then dropped. The enrollment the student actually lands in has no
term. The promotion record says "into 2026-27, Semester 2"; the enrollment record says
only "2026-27".

**Compounding structural blocker:** the enrollment tuple is
`(college_id, student_id, academic_year_id, program_id)` with section excluded. Promoting
a student into Semester 1 and then Semester 2 of the *same* academic year and program is
therefore rejected twice over — by `assertNoTargetEnrollment()` at request time (`:131`,
`:483`) and by `StudentService::hasNonDeletedEnrollmentForTuple` at approval. **Semester
progression is not merely unrecorded today; it is structurally impossible** without a
schema or tuple change.

### 3.3 Master data readiness

`AcademicTerm` is already fully capable:

```php
// app/Models/AcademicTerm.php
public const TYPES = ['semester', 'term', 'trimester', 'other'];
public const STATUSES = ['active', 'inactive'];
```

with `sequence` (`unsignedSmallInteger`, default 1) for ordering and
`unique(college_id, academic_year_id, code)`. So both year-based and term-based models are
representable in the master data; only the enrollment layer and the tuple rule prevent
term-based progression.

### 3.4 No migration is proposed in this phase

Per the scope restriction, nothing here is implemented. Section 7 isolates the migration
that C-1 would eventually require.

---

## 4. Academic performance

### 4.1 Repeat, detention, backlog, arrear — none exist

A case-insensitive grep for `backlog`, `detention`, `detained`, `repeat_year`,
`repeat year`, `carry.forward` and `arrear` across `app/` and `database/migrations`
returned **zero matches**. No such rule exists anywhere in the system.

### 4.2 What examination data does exist

`app/Models/ExamResult.php` keeps three deliberately separate status dimensions:

```php
// calculation_status : pending | calculated | incomplete | failed
// result_status      : pass | fail | absent | withheld | incomplete
// publication        : derived from published_at → published | unpublished
```

`ExamResult` links through `student_enrollment_id`, `academic_year_id`,
`academic_term_id`, `examination_id`, `grade_scale_id`. `ExamResultItem` mirrors the same
pass/fail vocabulary (`STATUS_PASS = ExamResult::RESULT_PASS`). `ResultCalculationService`
produces these rows; it is a snapshot generator, not a policy engine.

### 4.3 The progression ledger already has the right vocabulary

`app/Models/StudentAcademicRecord.php`:

```php
public const ACADEMIC_STATUSES   = ['enrolled', 'passed', 'failed', 'withdrawn', 'discontinued'];
public const PROMOTION_STATUSES  = ['not_applicable', 'pending', 'promoted', 'retained', 'transferred'];
public const COMPLETION_STATUSES = ['pending', 'completed', 'incomplete'];
```

`retained` — the detention/repeat outcome — **already exists as a first-class value**. Its
own docblock states the intent: *"Deliberately result-agnostic: no marks or grades live
here. The future Examination/Result module attaches to this record."*

### 4.4 Are these records connected to promotion? No.

- A grep for `StudentAcademicRecord` in `app/Domain/Student/Actions/` returns **nothing**.
- A grep for `ExamResult` / `examination` in `app/Domain/Student/Actions/` and
  `StudentPromotionController` returns **nothing**.
- The only writers of `promotion_status` are `StudentAcademicRecordService` (default
  `'not_applicable'`) and the `Store`/`UpdateStudentAcademicRecordRequest` rules
  (`required`, `Rule::in(PROMOTION_STATUSES)`) — i.e. **an operator types it in by hand**.
  Nothing derives it from exams, and nothing writes it when a promotion is approved.
- `ConsolidatedReportService.php:520` emits `'promotion_status' => null`.

**Confirmed defect C-3 (disconnection, not a wrong value):** approving a promotion never
touches the student's `StudentAcademicRecord`. A promoted student's ledger keeps whatever
`promotion_status` an operator last typed — commonly the default `not_applicable` — so the
progression ledger and the promotion table can and do disagree. `StudentHistoryService`
reads *both* independently (`:217-219` for records, `:228-251` for promotions), so the
student history timeline shows two unrelated accounts of the same year.

---

## 5. Enrollment consistency

### 5.1 The tuple rule

Documented in `2026_09_18_000002_create_student_enrollments_table.php`:

> The lifecycle invariant is one non-soft-deleted row per
> `(college_id, student_id, academic_year_id, program_id)` tuple across all statuses.
> A NULL `program_id` is one shared group; `section_id` is not part of the tuple.
> **No tuple-uniqueness index is declared here.**

Enforcement is transactional in `StudentService` (`hasNonDeletedEnrollmentForTuple`), not
at the database level. The migration comment explicitly defers any DB constraint until the
production engine/version is confirmed and a read-only duplicate preflight has been run.

### 5.2 Nothing caps the number of active enrollments

The tuple rule constrains one `(year, program)` pair. A student can legitimately hold two
`active` enrollments in different pairs — 2026-27/BSc and 2027-28/BSc, or 2026-27/BSc and
2026-27/MSc — and nothing in the codebase prevents or flags it.

### 5.3 Downstream impact of multiple active enrollments

| Consumer | Behaviour | Location |
| --- | --- | --- |
| TC issuance | **Hard block.** `activeEnrollments.count() > 1` → "This student has multiple active enrollments in this college. Reconcile them before issuing a transfer certificate; no records were changed." | `StudentTransferService.php:279-285` |
| TC issuance | Second guard: if exactly one active enrollment exists it must be the one linked to the transfer | `:287-292` |
| Promotion approval | Completes only the **source** enrollment. A second active enrollment in another tuple survives untouched → the student still has 2 active rows afterwards | `PromoteStudent.php:253` |
| Student list / reports | `ENROLLMENT_FILTERS` is applied inside a single `whereHas('enrollments', …)`, so "2026 + BSc" means one enrollment that is both — correct, but it silently picks whichever row matches | `StudentListService.php:67-74` |
| Student history | Lists every promotion and every enrollment as separate timeline entries; no reconciliation | `StudentHistoryService.php:228-251` |

**Confirmed defect C-4 (cross-module):** a second active enrollment permanently blocks TC
issuance for that student, and the promotion workflow is one of the easiest ways to create
one (promote a student who already has an active enrollment in another program). The
failure surfaces far away from its cause, in the TC screen.

Per the scope restriction, **no enrollment uniqueness rule is changed in this phase.**

---

## 6. Authorization

### 6.1 Permissions that exist

`database/seeders/DatabaseSeeder.php`

```php
// :67
'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update', 'student_enrollments.delete',
// :71
'student_promotions.view', 'student_promotions.create', 'student_promotions.approve',
```

Three promotion permissions. **There is no `student_promotions.cancel`.**

### 6.2 Who can do what

`app/Policies/StudentPromotionPolicy.php`

| Action | Ability | Permission |
| --- | --- | --- |
| list | `viewAny` | `student_promotions.view` |
| view one | `view` | `student_promotions.view` |
| record a request | `create` | `student_promotions.create` |
| approve | `approve` | `student_promotions.approve` |
| **cancel** | `approve` | `student_promotions.approve` |

`StudentPromotionController::cancel():101-111` calls `$this->authorize('approve', $model)`.
The policy docblock acknowledges this as deliberate. `StoreStudentPromotionRequest::authorize()`
checks `can('create', StudentPromotion::class)`.

### 6.3 Does promotion approval bypass enrollment permissions? Yes.

`PromoteStudent::approve()` writes enrollments directly:

- `:231` `$this->students->createEnrollment([...])`
- `:254` `$this->students->updateEnrollment($source, ['status' => 'completed'], $collegeId)`

A grep for `hasPermission`, `Gate::` and `authorize` across `StudentService.php` and
`PromoteStudent.php` finds **only a docblock comment** in `StudentService`. Neither class
performs a permission check. Authorization happens once, at the controller, against
`student_promotions.approve`.

**Consequence:** a role holding `student_promotions.approve` but *not*
`student_enrollments.create` / `.update` can still create enrollments and flip a source
enrollment to `completed`. The policy docblock frames the split as intentional
(*"Approving a promotion writes enrollments, so it is deliberately a separate, higher
privilege"*), and that framing is defensible — but it describes only one direction. The
unstated half is that **revoking `student_enrollments.create` does not stop enrollment
creation**, because promotion is an independent write path. Nothing is currently
documented about that, so an administrator tightening enrollment permissions will not get
the outcome they expect.

This is recorded as an open decision (**D-5**), not silently changed.

---

## 7. Confirmed defects versus unresolved business decisions

### 7.1 Confirmed defects

These are internal inconsistencies or lost data. None depends on an institutional policy
being chosen first.

| ID | Defect | Location |
| --- | --- | --- |
| **C-1** | A promotion's target academic term is validated, stored and audited, then dropped — `student_enrollments` has no term column. The decision record and the enrollment record disagree. | `PromoteStudent.php:105/142/224/262` vs `:231-244` |
| **C-2** | An academic year's `status` can never be changed after creation: no `update` route or controller method exists, and the single-active-year rule runs only inside `store()`. | `AcademicYearController.php` (index + store only), `routes/web.php:210-211` |
| **C-3** | Approving a promotion never updates `StudentAcademicRecord`. The progression ledger (`promotion_status`, default `not_applicable`) and `student_promotions` are independent, and student history renders both as separate, unreconciled accounts. | `PromoteStudent.php` (no reference), `StudentAcademicRecordService.php:67`, `StudentHistoryService.php:217-251` |
| **C-4** | Nothing caps the number of active enrollments across tuples, and a second one permanently blocks TC issuance with an error that points at the TC screen rather than its cause. | `StudentTransferService.php:279-292` |
| **C-5** | The promotion UI offers every student and every enrollment regardless of status, including `withdrawn` ones the action will reject. The refusal is correct; the offering is misleading. | `StudentPromotionController.php:117-137` (`studentOptions`, `enrollmentOptions`) |

### 7.2 Unresolved business decisions

Each needs an institutional answer. **None should be encoded by inference.**

| ID | Decision | Options seen in code |
| --- | --- | --- |
| **D-1** | May a `graduated` student be promoted? A graduated student has finished; giving them a new `active` enrollment is arguably the same class of error as the `withdrawn` case that *is* blocked. | Currently allowed |
| **D-2** | May an `inactive` or `suspended` student be promoted? Suspension is a hold, not an exit — but promoting during it silently lifts the hold. | Currently allowed |
| **D-3** | May a `cancelled` source enrollment be promoted from? The code carefully preserves the cancellation (`:246-252`) yet still creates a new `active` enrollment derived from it. | Currently allowed |
| **D-4** | Is backward or gap-skipping year progression legitimate (lateral entry, re-admission, correction), or should the target be constrained to the next chronological year / the active year? The module docblock claims lateral entry and re-admission are supported use cases. | Currently unrestricted |
| **D-5** | Should `student_promotions.approve` imply enrollment write rights, or should approval additionally require `student_enrollments.create` + `.update`? | Currently implies |
| **D-6** | Should cancellation get its own permission and audit action? It currently shares `student_promotions.approve`. | Currently shared |
| **D-7** | Is the institution year-based or term-based? This single answer determines whether C-1 is a bug to fix or a schema change to schedule. | Year-based today |
| **D-8** | Should exam results gate promotion (pass-only, or backlog/detention rules)? No rule exists, and none should be invented. | Not implemented |

---

## 8. Risks to existing records and workflows

1. **Backfill trap on any eligibility tightening (D-1/D-2/D-3).** Adding a status to
   `assertPromotable()` immediately invalidates pending requests already in the table.
   Those rows were created legally under the old rule. Any tightening must decide,
   explicitly, what happens to them — refuse at approval (surfaces as a new failure on an
   existing record) or grandfather them. Neither is free.
2. **C-1 cannot be fixed by editing existing data.** Existing promotions carry a
   `target_academic_term_id` that was never applied. Backfilling it onto enrollments would
   require inferring which term each historical enrollment belonged to — information the
   system never recorded. Any future fix is forward-only.
3. **C-4 is a landmine for TC issuance.** A student with two active enrollments cannot get
   a TC at all, and the message does not explain that promotion caused it.
4. **C-3 means reports are already inconsistent.** Any report or timeline joining
   `student_promotions` and `student_academic_records` is comparing two independently
   maintained accounts. Consumers: `StudentHistoryService`, `StudentReportService`,
   `ConsolidatedReportService`, `FinanceReportService`, `HostelReportService`.
5. **Permission expectations (D-5).** An administrator who revokes
   `student_enrollments.create` will still see enrollments appear via promotion.
6. **No database-level uniqueness.** The tuple rule is application-enforced only, so any
   future code path that writes `student_enrollments` outside `StudentService` bypasses it
   entirely.
7. **`phpunit.xml` targets sqlite `:memory:`**, so `FOR UPDATE` locking is not exercised by
   the suite. Lock-ordering regressions would not be caught by tests.

---

## 9. Recommended policy options

### 9.1 Eligibility (D-1, D-2, D-3)

**Option A — deny-list extension (recommended).** Add `graduated` to the student guard,
keep `inactive` and `suspended` promotable, keep `completed` and `cancelled` sources
promotable.
*Pros:* smallest change; fixes the case that is unambiguously wrong (a graduated student
cannot be currently enrolled); consistent with the existing `withdrawn` reasoning; no
impact on lateral entry or re-admission.
*Cons:* still permits promoting a suspended student; leaves D-2 open.

**Option B — allow-list.** Only `active` students with `active` or `completed` sources.
*Pros:* strongest guarantee; matches a "promotion = continuing study" reading.
*Cons:* breaks re-admission from a `cancelled` enrollment, which the module docblock
claims to support; largest blast radius on pending records.

**Option C — status quo plus UI filtering.** Leave the guard, fix C-5 so ineligible
records are not offered.
*Pros:* zero behaviour change; no backfill question; removes the misleading path.
*Cons:* the API still accepts them; a determined or scripted caller can still promote a
graduated student.

*Recommendation:* **A + C together.** A is a two-line change with a clear rationale; C
removes the UI trap. Defer D-2 pending an institutional answer on suspension.

### 9.2 Year progression (D-4)

**Option A — warn, do not block.** Record backward/gap progression in `remarks` and
surface it in the UI, but do not reject.
*Pros:* preserves lateral entry and re-admission; no policy invented.
*Cons:* relies on operator diligence.

**Option B — constrain to the next chronological year.** Use `starts_on` ordering to
require the immediate successor.
*Pros:* eliminates C-1-adjacent mistakes and makes progression auditable.
*Cons:* breaks re-admission and lateral entry; requires an explicit exception mechanism,
which is new policy.

**Option C — constrain to the *active* year.** Target must be the college's active year.
*Pros:* simple, uses data that already exists.
*Cons:* **blocked by C-2** — the active year cannot currently be maintained, so this would
strand colleges whose active year was set at creation and later deactivated.

*Recommendation:* **A now**, and treat **C** as the eventual target — but only after C-2
is fixed, otherwise it has no reliable input.

### 9.3 Term handling (D-7)

**Option A — year-based only, and stop pretending otherwise.** Remove
`target_academic_term_id` from the promotion form and action, so the system no longer
captures a value it discards.
*Pros:* eliminates C-1 honestly; no migration; no false expectation.
*Cons:* loses information institutions may want for reporting; discards a column already
in the schema (it would remain populated on historical rows).

**Option B — full term-based progression.** Add `academic_term_id` to
`student_enrollments` and include it in the uniqueness tuple.
*Pros:* makes semester/trimester progression actually work; aligns enrollment with
`academic_subject_enrollments`, `exam_results` and `student_academic_records`, which are
all already term-aware.
*Cons:* the tuple change is the single riskiest change in this document — it alters what
counts as a duplicate for every enrollment write path, and the migration comment at
`2026_09_18_000002` explicitly forbids adding a constraint before a duplicate preflight.

**Option C — record the term on the promotion only, and label it as such.** Keep the
column, and make the UI and docs state plainly that the term is a *decision annotation*
that does not carry onto the enrollment.
*Pros:* zero migration; removes the misunderstanding at near-zero cost.
*Cons:* C-1 technically remains.

*Recommendation:* **C immediately** (a documentation and labelling change), with **B**
scheduled as its own phase gated on D-7 and on the duplicate preflight. Do **not** choose
A — deleting the field destroys information that a term-based rollout would want.

### 9.4 Performance gating (D-8)

No option is recommended yet, because none exists in the codebase and none should be
invented. The useful preparation, which requires no policy decision, is to close C-3 so
that a promotion writes `promotion_status` on the student's `StudentAcademicRecord`. That
gives any future pass/fail or detention rule a place to write to — `retained` is already a
valid value.

### 9.5 Authorization (D-5, D-6)

Document the current implication rather than change it: `student_promotions.approve`
grants enrollment creation and update as a side effect. If a separation is wanted, the
minimal change is an additional `Gate` check inside `approve()`; adding a distinct
`student_promotions.cancel` permission is a separate, low-risk change.

---

## 10. Phased implementation plan

Every phase is independently shippable, ordered so that no phase depends on a decision
that has not been made.

### Phase E2.3 — Correctness with no policy change

**Depends on:** nothing. **Migration:** none. **Policy invented:** none.

1. Fix **C-5** — filter `studentOptions()` to exclude `withdrawn` students and
   `enrollmentOptions()` to exclude `withdrawn` enrollments
   (`StudentPromotionController.php:117-137`).
2. Fix **C-3** — on approval, upsert the student's `StudentAcademicRecord` for the target
   year with `promotion_status = 'promoted'`, and on cancellation restore
   `'not_applicable'` only where the promotion had set it.
3. Address **C-1** by labelling: rename the form field and add a note that the term is a
   decision annotation not carried onto the enrollment.

**Focused tests**

- the promotion create form no longer offers a withdrawn student or a withdrawn enrollment
- approving a promotion writes `promotion_status = 'promoted'` on the target-year record
- approving does not create a duplicate record when one already exists for that year
  (respects the `(student, year, term)` uniqueness in `StudentAcademicRecordService`)
- cancelling a promotion leaves an unrelated, operator-set `promotion_status` untouched
- a promotion into a year with an existing record preserves that record's
  `academic_status` and `completion_status`

### Phase E2.4 — Academic year lifecycle (fixes C-2)

**Depends on:** nothing. **Migration:** none. **Policy invented:** none.

1. Add `update` for academic years behind the existing `AcademicYear` policy, reusing
   `store()`'s transaction shape: College lock → overlap check → deactivate others →
   commit.
2. Guard against deactivating the only active year, and audit the transition.

**Focused tests**

- activating a year deactivates the previously active one, atomically
- creating/updating overlapping years is refused
- a year can be reactivated after being superseded
- a foreign college's year 404s
- the transition is audited with old and new status
- deactivating the last active year is refused

### Phase E2.5 — Eligibility tightening (needs D-1, and D-2/D-3 if Option B)

**Depends on:** a written decision on D-1. **Migration:** none.

1. Extend `assertPromotable()` with the chosen statuses.
2. Decide and document the treatment of pending requests created before the change.

**Focused tests**

- each newly rejected status is refused at **request** time with a clear message
- each is also refused at **approval** time when the status changed after the request
- the shared-guard test (`test_request_and_approval_share_one_eligibility_guard`) is
  extended so both callers stay provably identical
- an explicitly grandfathered pending request behaves as decided
- `active` students with `active` and `completed` sources still promote

### Phase E2.6 — Progression direction (needs D-4)

**Depends on:** D-4, and Phase E2.4 if the target is "the active year". **Migration:** none.

1. Implement the chosen rule (warn, next-chronological, or active-year-only).
2. Add an explicit, audited exception path if backward progression must remain possible.

**Focused tests**

- forward promotion into the next chronological year is accepted
- the chosen constraint rejects a backward target with a clear message
- gap-skipping behaves as decided
- an exception, where the policy allows one, is recorded and audited
- the rule is enforced identically at request and approval

### Phase E2.7 — Enrollment ceiling (fixes C-4; needs its own decision)

**Depends on:** a decision on whether one active enrollment per student is the intended
invariant. **Migration:** none for the guard; a DB constraint would need the preflight.

1. Add a guard refusing to create a second active enrollment for a student.
2. Improve the TC issuance message to point at the enrollment that must be reconciled.

**Focused tests**

- a second active enrollment in another program is refused
- promotion for a student who already has an active enrollment in another tuple is refused
  with a message naming the other enrollment
- the existing multi-active TC refusal still fires for historical data
- completing or withdrawing the first enrollment unblocks the second

### Phase E2.8 — Authorization documentation and separation (needs D-5, D-6)

**Depends on:** D-5 and D-6. **Migration:** none; a new permission needs a seeder entry.

1. Document the enrollment-write implication of `student_promotions.approve`.
2. Optionally add `student_promotions.cancel` and a distinct audit action.

**Focused tests**

- a user with `student_promotions.approve` but not `student_enrollments.create` behaves
  exactly as the decided policy says
- cancellation requires the decided permission
- existing roles keep working after the seeder change

### Phase E2.9 — Term-based progression (needs D-7 and the duplicate preflight)

**Depends on:** D-7, the duplicate preflight named in
`2026_09_18_000002_create_student_enrollments_table.php`, and Phases E2.3–E2.4.
**Migration:** required — see section 11.

---

## 11. Migration proposal (separate from implementation)

**No migration is proposed for Phases E2.3 through E2.8.** They are all expressible with
existing columns.

Only Phase E2.9 needs one, and it should not be started until D-7 is answered:

**M-1 — `student_enrollments.academic_term_id`**

- `nullable`, `constrained('academic_terms')->nullOnDelete()`, indexed as
  `(college_id, academic_term_id)` — structurally identical to how `section_id` was added
  in `2026_09_19_000006`, which is the precedent for extending this table safely.
- Nullable means every existing enrollment row keeps working unchanged and no production
  data is rewritten.
- Backfill is **not** possible: the system never recorded which term an enrollment
  belonged to. Rows stay `NULL`, meaning "year-based". Any tooling must treat `NULL` as a
  valid, permanent value rather than missing data.

**M-2 — tuple extension (deferred, and gated)**

Extending the uniqueness tuple from `(college, student, year, program)` to include the
term is what actually enables two enrollments in one year. It changes what counts as a
duplicate for **every** enrollment write path, including bulk actions and seeding.

The existing migration states the precondition explicitly:

> No tuple-uniqueness index is declared here. Before any future database constraint is
> considered, confirm the production engine/version and run the read-only duplicate
> preflight documented in `docs/student_management_foundation_design.md`. Do not resolve
> historical duplicates by deleting or rewriting enrollment records.

That preflight is a hard gate. It must be run and its result reviewed before M-2 is
written, and M-2 must not be bundled with M-1.

---

## 12. Recommended next step

**Phase E2.3.** It fixes C-5 and C-3 and resolves C-1 by labelling — three real
inconsistencies, no migration, no permission change, and no institutional policy required.
It also creates the `StudentAcademicRecord` linkage that every later phase would otherwise
have to retrofit.

In parallel, three answers are needed before E2.5, E2.6 and E2.9 can be scheduled:

- **D-7** — is the institution year-based or term-based? (gates the largest piece of work)
- **D-1** — may a graduated student be promoted?
- **D-4** — must the target year be the next chronological year, the active year, or
  unconstrained?
