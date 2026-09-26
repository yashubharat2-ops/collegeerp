# Inventory / Asset Management — Phase 3 (Issue, Assignment, Return, Maintenance)

**Scope of this phase:** item issue / allocation for consumable stock, asset
assignment (custody) of individual assets, asset return, and asset
maintenance — four screens added to the EXISTING single
"Inventory / Asset Management" sidebar section.

The future inventory reports module is deliberately **not** implemented, and
there is **no separate Asset master**: individual assets are the
`item_type = 'asset'` rows of the existing Items / Assets master, and no
`assets` table or `assets.view` permission is introduced.

Menu (all in the existing section, each entry gated by its OWN permission
family; the section gate was extended, no new section):
- **Inventory / Asset Management** → the 8 Phase 1 + 2 entries, plus:
  - Item Issue / Allocation (`inventory_issues.*`)
  - Asset Assignment (`inventory_assignments.*`)
  - Asset Return (`inventory_asset_returns.*`)
  - Asset Maintenance (`inventory_maintenance.*`)

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_30_000101_create_inventory_issues_table`, `…000102_create_inventory_assignments_table` (partial unique index `(item_id) WHERE status = 'active'` on SQLite/PostgreSQL), `…000103_create_inventory_maintenances_table` — additive only, no existing table modified |
| Models | `InventoryIssue`, `InventoryAssignment`, `InventoryMaintenance` (BelongsToCollege + CollegeScope; morphTo `issued_to` / `assigned_to` on the `students` / `faculties` tables) |
| Domain | `App\Domain\Inventory\Services\{InventoryIssueService, InventoryAssignmentService, InventoryMaintenanceService}` — **reuse** `InventoryStockService::apply()` for every stock change, `InventoryFormOptions` for the dropdowns, `AuditLogService` for the audit trail |
| Policies | `InventoryIssuePolicy`, `InventoryAssignmentPolicy` (answers both the assignment family and the return family: `viewReturns` / `returnAsset`), `InventoryMaintenancePolicy` (registered in `AuthServiceProvider`) |
| HTTP | `InventoryIssueController`, `InventoryAssignmentController`, `InventoryAssetReturnController`, `InventoryMaintenanceController`; Form Requests `StoreInventoryIssueRequest`, `StoreInventoryAssignmentRequest`, `StoreInventoryAssetReturnRequest`, `StoreInventoryMaintenanceRequest`, `UpdateInventoryMaintenanceRequest` |
| Views | `inventory_issues/{index,create}`, `inventory_assignments/{index,create}`, `inventory_asset_returns/index` (per-row return form), `inventory_maintenances/{index,create,edit}` |
| RBAC | 9 slugs in `InventoryPhase3PermissionSeeder` (2 + 2 + 2 + 3), spread into `DatabaseSeeder` (grants both seeded admin roles) |
| Routes | `inventory-issues.{index,create,store}`, `inventory-assignments.{index,create,store}`, `inventory-asset-returns.{index,store}`, `inventory-maintenances.{index,create,store,edit,update}` |
| Navigation | 4 new links in the existing section; section gate extended with the 4 new view permissions |
| Morph map | `Relation::morphMap(['student' => Student::class, 'faculty' => Faculty::class])` registered in `AppServiceProvider` (additive — FQCN-based morphs such as the audit log's `subject` keep working) |

## Item Issue / Allocation

One row = one issue of CONSUMABLE stock to a student or a staff member. The
stock itself never moves in this table: the issue writes a `stock_out`
movement through the EXISTING `InventoryStockService` in the same
transaction, and the issue's auto number (`ISS-000001`, per college) travels
as that movement's `reference` — so every issued unit is reconcilable from
the Inventory Transactions screen.

- Only ACTIVE consumables can be issued; an asset is refused with a pointer
  to Asset Assignment.
- The recipient is an existing, same-tenant, non-deleted row of `students`
  or `faculties` (staff are the `faculties` table, which also backs the HR
  Employee alias).
- Quantity is a positive decimal; the existing negative-balance guard still
  applies (422 on the `quantity` field, nothing written).
- `number` is unique per college; a concurrent collision is retried against
  the unique index (up to 3 times), never hand-merged.
- **Append-only**: no update, no delete route. Returned stock is a new
  incoming movement, never an edit of the issue.

## Asset Assignment

One row = one lending of an individual ASSET. Assignment is **custody, not
consumption**: the asset's on-hand quantity and the stock ledger are
untouched — the assignment rows ARE the asset's custody history.

- At most ONE active assignment per asset. The check runs inside a
  transaction that locks the item row; on SQLite/PostgreSQL the partial
  unique index `(item_id) WHERE status = 'active'` is the database-level
  second guard (other drivers rely on the row-locked service check, the same
  pattern the library circulation uses).
- The assignee is a same-tenant student or staff member.
- An asset with an active assignment cannot be assigned again (422 on
  `item_id`); a consumable cannot be assigned (422 pointing at issuing).
- **Append-only**: no update, no delete route.

## Asset Return

A return flips an ACTIVE assignment to `status = 'returned'` with the return
date, the acting user and optional notes — the row is updated IN PLACE and
never deleted, so the full custody trail (who held it, since when, who took
it back, notes) stays in place. A later re-assignment of the same asset is a
NEW row.

- Double-return is refused (422 on `assignment_id`); the original return
  data is untouched.
- The listing shows ACTIVE assignments only; a view-only user sees the list
  without the per-row return form (gated by `inventory_asset_returns.create`).
- No stock movement is written on return.
- The return ability lives on the assignment policy as `returnAsset`; the
  request-level authorize is a direct slug check and the controller
  re-checks the ability against the loaded instance.

## Asset Maintenance

One row = one maintenance event (preventive, repair, inspection,
calibration, other) for an individual ASSET — always a reference to an
EXISTING `inventory_items` row via the composite
`(item_id, college_id)` foreign key; nothing in this module creates or
duplicates an asset. Optional `vendor_id` (composite foreign key) reuses the
Phase 1 vendor master for externally performed work.

- Unlike the append-only history modules, a maintenance record is a **live
  work order**: it can be edited as the status walks
  `scheduled → in_progress → completed`, with costs and the completion date
  filled in along the way. Editing is the correction path; `item_id` is
  fixed at creation and stripped from update payloads.
- `completed` requires `completed_on` (422 when missing).
- The table carries soft deletes for this reason, but there is still **no
  delete route** — records are corrected, not thrown away.
- Stock is never involved: maintenance is work on the item, not a movement
  of it.

## Tenancy and data integrity

- Every table is tenant-scoped (BelongsToCollege + CollegeScope) and linked
  to its parents with composite `(x_id, college_id)` foreign keys — the same
  convention as Phases 1 + 2, so a row can never point at another
  college's record (enforced at the database level, tested).
- The assignment / issue / maintenance rows all reference `inventory_items`
  through `(item_id, college_id)`, resolvable thanks to the plain unique
  `(id, college_id)` parent key added in Phase 2
  (`2026_09_30_000004_add_inventory_composite_foreign_key_parent_keys`).
- People are never duplicated: recipients / assignees are polymorphic
  references (`issued_to` / `assigned_to`) into the existing `students` and
  `faculties` tables; the short type keys resolve through the morph map
  registered in `AppServiceProvider`.
- Every create / return / update is audit-logged through the existing
  `AuditLogService` (`inventory_issues.created`,
  `inventory_assignments.created` / `.returned`,
  `inventory_maintenances.created` / `.updated`).

## Permissions (9, one family per module)

| Module | Slugs |
| --- | --- |
| Item Issue / Allocation | `inventory_issues.view`, `inventory_issues.create` |
| Asset Assignment | `inventory_assignments.view`, `inventory_assignments.create` |
| Asset Return | `inventory_asset_returns.view`, `inventory_asset_returns.create` |
| Asset Maintenance | `inventory_maintenance.view`, `inventory_maintenance.create`, `inventory_maintenance.update` |

Each menu is controlled ONLY by its own family. Issue / assignment / return
are append-only so there is no update or delete permission for them
(`inventory_maintenance.update` exists because maintenance is live work).
`inventory_reports.view` and `assets.view` are deliberately NOT seeded —
reports are a later phase and no asset master exists.

## Tests

`tests/Feature/Inventory/`:

- `InventoryPhase3SeederTest` — the 9 slugs seeded once, granted to both
  seeded admin roles, standalone seeder idempotent, reports / asset-master
  slugs absent.
- `InventoryIssueTest` — ledger linkage (stock-out movement carries the
  issue number, balance snapshot), per-college number sequence, asset and
  inactive-item refusal, negative-stock refusal, same-tenant recipient
  (student + faculty), tenant isolation, RBAC, append-only route surface.
- `InventoryAssignmentTest` — custody leaves stock and ledger untouched,
  one-active-assignment rule, consumable refusal, same-tenant assignee,
  tenant isolation, status filter, RBAC, append-only route surface.
- `InventoryAssetReturnTest` — in-place history preservation, re-assignment
  as a new row, double-return refusal, view-only vs create users,
  tenant isolation, the partial unique index and the composite foreign key
  at the database level.
- `InventoryMaintenanceTest` — the fixed asset link, vendor reuse, the
  status walk with the completed-date rule, `item_id` immutability, RBAC
  per permission, tenant isolation (foreign id = 404), type/status filters.
- Existing `InventoryNavigationTest`, `InventoryModuleSeederTest`,
  `InventoryPhase2SeederTest` and `InventoryPurchaseOrderTest` were updated:
  the section now holds exactly 12 entries, the Phase 3 slugs are seeded,
  the Phase 3 tables exist, and no new sidebar section or future module
  leaked in.
