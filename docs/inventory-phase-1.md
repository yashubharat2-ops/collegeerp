# Inventory / Asset Management — Phase 1

**Scope of this phase:** the catalogue masters only — item categories, a single
items / assets master, and vendors — plus a read-only **Inventory Dashboard**
over them. There is no separate Asset master.

> **Superseded in part by [Phase 2](inventory-phase-2.md).** Purchase orders and
> stock in/out are now implemented. Issue/return to staff, asset assignment,
> maintenance and reports are still **not** implemented.

Menu: **Inventory / Asset Management → Inventory Dashboard, Item Categories,
Items / Assets, Vendors** (exactly four entries, each individually
permission-gated).

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_29_000001_create_inventory_categories_table`, `…000002_create_inventory_vendors_table`, `…000003_create_inventory_items_table` (additive only) |
| Models | `InventoryCategory`, `InventoryItem`, `InventoryVendor`; marker `InventoryDashboard` (not Eloquent) |
| Domain | `App\Domain\Inventory\Services\{InventoryCategoryService, InventoryItemService, InventoryVendorService, InventoryDashboardService}` |
| Policies | registered in `AuthServiceProvider` |
| HTTP | controllers under `App\Http\Controllers\Inventory`; Form Requests under `App\Http\Requests\Inventory` |
| RBAC | 13 slugs in `InventoryPermissionSeeder`, spread into `DatabaseSeeder` |

## Data rules

* `college_id`, `created_by` and `updated_by` are server-controlled.
* Codes are stored upper-cased and unique among a college's active (not
  soft-deleted) rows. Another college may reuse the same code. A soft-deleted
  code can be reused.
* `serial_number`, when provided, is stored upper-cased and unique among the
  college's active items. Empty serials do not collide.
* `category_id` must reference a non-deleted category of the same college
  (form-request exists rule, service guard, and composite foreign key
  `(category_id, college_id)`).
* A category that still classifies items cannot be deleted.
* Soft deletes on all three masters.
