# Finance / Fees — Fee Structure Foundation

**Scope of this milestone:** the fee *definition* layer only — `fee_structures`
and `fee_structure_items`. Fee Collection, Receipts, Discounts, Fines, Refunds
and Reports are deliberately **not** implemented; their tables are not created.

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_21_000005_create_fee_structures_table`, `2026_09_21_000006_create_fee_structure_items_table` |
| Models | `App\Models\FeeStructure`, `App\Models\FeeStructureItem` |
| Domain service | `App\Domain\Finance\Services\FeeStructureService` |
| Policy | `App\Policies\FeeStructurePolicy` (registered in `AuthServiceProvider`) |
| HTTP | `FeeStructureController`, `StoreFeeStructureRequest`, `UpdateFeeStructureRequest`, `Route::resource('fee-structures')->except('show')` |
| Views | `resources/views/fee_structures/{index,create,edit,_form}.blade.php` |
| Navigation | new **Finance / Fees** sidebar section holding exactly one entry: *Fee Structures* |
| RBAC | `fee_structures.view`, `fee_structures.create`, `fee_structures.update`, `fee_structures.delete` (centralized `DatabaseSeeder`, idempotent) |
| Tests | `tests/Feature/Finance/{FeeStructureManagementTest, FeeStructureTenancyTest, FeeStructureAuthorizationTest, FeeStructureNavigationTest, FinanceModuleSeederTest}` |

No Platform master is duplicated: a fee structure references the existing
`college_id`, `academic_year_id`, `program_id` and (optional)
`academic_term_id`.

## Tenancy

* `FeeStructure` and `FeeStructureItem` both use `BelongsToCollege`, so every
  query runs under `CollegeScope` and `college_id` is stamped from
  `TenantContext` — a browser-supplied `college_id` is stripped by the Form
  Requests and never reaches the database.
* Foreign keys are validated **contextually** in the Form Requests:
  the academic year and program must belong to the active college, and a
  selected term must belong to both that college **and** the submitted academic
  year (`Rule::exists(...)->where('college_id', …)->where('academic_year_id', …)`).
* Fee heads may only be updated inside their own structure
  (`items.*.id` is validated against `fee_structure_id`), and the service
  re-checks tenant ownership before every write.

## Data rules

* **Amounts are money:** `decimal(12,2)`, never float. Non-negativity is
  enforced in three layers — Form Requests (`min:0`), the service and the
  `FeeStructureItem::saving` guard (which also protects seeders/tinker) — plus a
  `CHECK (amount >= 0)` constraint on the engines that can add one to an
  existing table (MySQL/MariaDB 8+/PostgreSQL/SQL Server). SQLite cannot, so it
  relies on the application layers — the same approach `academic_years` takes
  for its date rule.
* **Duplicate prevention:** at most one *active* (i.e. not soft-deleted)
  structure per `(college_id, academic_year_id, program_id, code)`, enforced by
  a partial unique index on SQLite/PostgreSQL and by the service guard
  everywhere (`ValidationException` on `code`). Soft-deleted rows are history
  and never block re-creating a code — exactly like `grade_scales`.
* **Fee heads** are unique per structure (case-insensitively in the
  application), carry a deterministic `sort_order`, and follow their parent's
  lifecycle (`cascadeOnDelete`, no soft deletes on the child rows).
* **Deleting** a structure soft-deletes it, removes its fee heads in the same
  transaction and records the removed fee heads in the audit entry. Nothing is
  destroyed.

## Audit

`fee_structures.created|updated|deleted` and
`fee_structure_items.created|updated|deleted` are written through the shared
`AuditLogService`, with `college_id`, `user_id`, subject morph and old/new value
snapshots. `created_by` / `updated_by` are stamped on the structure.

## RBAC and navigation

Each action is gated on its own slug through the policy; a user holding one
permission never gains another, and a permission granted in another college
never authorises an action in the active one. The sidebar section is rendered
only when the user holds `fee_structures.view`.

## Deploying this milestone (non-destructive)

```bash
git pull origin main
php artisan migrate --force     # additive migrations only
php artisan db:seed --force     # idempotent: registers the four fee_structures.* slugs
```

A database seeded before these slugs existed silently hides the module (every
policy check returns `false`); the re-seed above fixes it without touching any
existing row. Verify with:

```bash
php artisan tinker --execute="\App\Models\Permission::where('slug','like','fee_structures.%')->pluck('slug')->implode(', ');"
```

## Explicitly deferred

Fee Collection, Receipts, Discounts / Concessions, Fines, Refunds, fee
schedules by instalment, and fee reports. The foundation is shaped so they can
reference `fee_structures.id` / `fee_structure_items.id` without schema changes
to the above.
