# Inventory / Asset Management — Phase 2 (Purchasing & Stock) — Final

**Scope of this phase:** purchase orders with their lines, goods receipts, stock
adjustments, and the immutable stock transaction ledger.

Issue/return to staff, asset assignment, maintenance and inventory reports are
deliberately **not** implemented, and there is still no separate Asset master
and no payment record — Finance owns payments.

Architecture: **Purchase Order → Goods Receipt / Stock In → Inventory Transactions → Current Stock**
Stock Adjustment also generates an inventory transaction.

Menu:
- **Inventory / Asset Management** → Inventory Dashboard, Item Categories, Items / Assets, Vendors (4 entries, Phase 1 preserved)
- **Purchase & Stock** → Purchase Orders, Goods Receipt / Stock In, Stock Adjustment, Inventory Transactions (exactly 4 entries, each individually permission-gated)

The old **Stock Movements** menu has been removed/renamed and refactored into the three final modules:
- Goods Receipt / Stock In (incoming: purchase_receipt + stock_in)
- Stock Adjustment (adjustment + stock_out)
- Inventory Transactions (full immutable ledger)

Backward compatibility: old `inventory-stock.*` routes still resolve via the original controller but are no longer in the sidebar.

## What was added (final refactor)

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_30_000001_create_inventory_purchase_orders_table`, `…000002_create_inventory_purchase_order_items_table`, `…000003_create_inventory_stock_movements_table`, `…000004_add_inventory_composite_foreign_key_parent_keys` (additive only, reused) |
| Models | `InventoryPurchaseOrder`, `InventoryPurchaseOrderItem`, `InventoryStockMovement` (reused, no new tables) |
| Domain | `App\Domain\Inventory\Services\{InventoryPurchaseOrderService, InventoryStockService}` (reused) |
| Policies | `InventoryPurchaseOrderPolicy`, `InventoryStockMovementPolicy` updated with `viewTransactions`, `viewGoodsReceipts`, `createGoodsReceipt`, `viewAdjustments`, `createAdjustment` (registered in `AuthServiceProvider`) |
| HTTP | `InventoryPurchaseOrderController` (kept), `InventoryGoodsReceiptController`, `InventoryStockAdjustmentController`, `InventoryTransactionController` (new, reuse service), `InventoryStockController` kept for backward compat; Form Requests `StoreInventoryGoodsReceiptRequest`, `StoreInventoryStockAdjustmentRequest` plus existing |
| Views | `inventory_goods_receipts/*`, `inventory_stock_adjustments/*`, `inventory_transactions/*` (new), `inventory_stock/*` kept for BC |
| RBAC | 14 slugs in `InventoryPhase2PermissionSeeder` (5 PO + 4 legacy stock + 5 new final modules), spread into `DatabaseSeeder` |
| Routes | `inventory-goods-receipts.*`, `inventory-stock-adjustments.*`, `inventory-transactions.*` plus legacy `inventory-stock.*` aliases |
| Navigation | `Purchase & Stock` section with exactly 4 entries, `Inventory / Asset Management` preserved with 4 Phase 1 entries; `Stock Movements` removed |

## Purchase orders

A purchase order is a tenant-scoped header plus its lines. Its lifecycle is
driven by the service, never by a posted status:

```
draft ──submit──▶ submitted ──receive (part)──▶ partially_received ──receive (rest)──▶ received
  │                   │                                │
  └────── cancel ─────┴───────────── cancel ───────────┘
```

* **Only a draft can be edited or deleted.** A submitted order is a promise to
  the vendor; a received one is already reflected in stock. Anything further
  along is cancelled, not removed (soft delete).
* `number` is stored upper-cased and unique among the college's active orders.
  A soft-deleted number can be reused, and another college may use the same one.
* `total_amount` is the **server-computed** sum of `quantity × unit_price` over
  the lines — never taken from the request.
* An item may appear only once per order (unique `(purchase_order_id,
  item_id)`, a `distinct` form rule and a service guard).
* `vendor_id` and every `item_id` must reference a non-deleted row of the same
  college: form-request `exists` rule, service guard, and the composite foreign
  keys `(vendor_id, college_id)` / `(item_id, college_id)`.
* A draft cannot be received; a received or cancelled order cannot be received
  again; an order with no lines cannot be submitted.

### Receiving (GRN)

`receive` writes one stock movement per receipt row and accumulates
`received_quantity` on the line, so an order can arrive in several
consignments. Blank rows mean "nothing arrived on that line"; a row may never
receive more than its outstanding quantity; an empty receipt is refused. The
order's status follows its lines — it is never posted.

## Stock ledger (now split into 3 final modules)

`inventory_stock_movements` is **append-only**: no update route, no destroy
route, no `deleted_at`. A correction is a new movement. Same table reused for
all 3 new modules.

| Type | Direction | Who writes it | Final module |
| --- | --- | --- | --- |
| `opening` | in | the item form, when a new item is recorded with a quantity | Transactions |
| `purchase_receipt` | in | receiving a purchase order | Goods Receipt / Stock In + Transactions |
| `stock_in` | in | Goods Receipt screen (manual) | Goods Receipt / Stock In + Transactions |
| `stock_out` | out | Stock Adjustment screen (a reason is required) | Stock Adjustment + Transactions |
| `adjustment` | in or out | Stock Adjustment screen, or a quantity corrected on the item form | Stock Adjustment + Transactions |

* `quantity` is always a positive magnitude; `direction` carries the sign.
* `balance_after` snapshots the on-hand quantity at write time, so the ledger
  explains itself and drift is visible.
* The item's `quantity` is updated in the **same transaction** as the movement,
  under a row lock, and on-hand stock can never go negative — an out movement
  larger than the balance is rejected with a field error.
* Because a quantity typed on the item form is recorded as an `adjustment`,
  every balance has a reason and no path can bypass the ledger.

## RBAC (final)

- `inventory_purchase_orders.view/create/update/delete/receive` (PO lifecycle)
- Legacy: `inventory_stock.view/in/out/adjust` (kept for BC, maps to new modules)
- Final: `inventory_goods_receipts.view/create`, `inventory_stock_adjustments.view/create`, `inventory_transactions.view`

`receive` is separate from `update` (booking goods in changes stock), and the
three stock abilities are separately grantable. Movements remain immutable —
no update/delete permission. New permissions reuse the same ledger table; no
duplicate business logic.

Policy checks both old and new slugs so existing grants keep working while new
modules are gated by their own slugs:

- Transactions: `inventory_transactions.view` OR `inventory_stock.view`
- Goods Receipt: view `inventory_goods_receipts.view` OR `inventory_stock.view`; create `inventory_goods_receipts.create` OR `inventory_stock.in`
- Stock Adjustment: view `inventory_stock_adjustments.view` OR `inventory_stock.view`; create `inventory_stock_adjustments.create` OR `inventory_stock.adjust` OR `inventory_stock.out`

## Composite foreign keys and their parent keys

Every relationship here is a composite `(x_id, college_id)` foreign key, which
is what stops a movement or an order line ever pointing at another college's
record. SQLite resolves such a key against a parent index when a statement is
prepared, and it requires a **non-partial UNIQUE** index covering the referenced
columns — the soft-delete-aware `… WHERE deleted_at IS NULL` indexes do not
count. Without one, *every* insert into the child failed:

```
SQLSTATE[HY000]: General error: 1 foreign key mismatch -
"inventory_stock_movements" referencing "inventory_purchase_orders"
```

even for a row whose `purchase_order_id` is NULL (an opening movement), because
the failure happens before any value is examined.

`2026_09_30_000004` adds the missing parent key — a plain UNIQUE index on
`(id, college_id)` — to `inventory_categories`, `inventory_vendors`,
`inventory_items` and `inventory_purchase_orders`. `id` is already unique, so
existing data can never violate it. With it in place the cross-tenant case
becomes a real `FOREIGN KEY constraint failed` rejection at the database level
instead of a silent mismatch, so tenant safety is enforced twice: in the service
and in the schema.

## Fixes found by the review pass

**A non-receivable order reports its status, not its quantities.** Whether an
order may accept goods is now checked first in
`InventoryPurchaseOrderService::validateReceipts()`, which the receiving form
request calls. A draft, a cancelled or a fully received order also has nothing
outstanding, so the old ordering answered with *"Only 0.00 of … is still
outstanding"* on `receipts.n.quantity` — sending the storekeeper to fix numbers
that were never the problem. Both paths now share `assertReceivable()`, and
`receive()` still re-checks it inside the row lock, so a concurrent receipt
cannot settle the order between validation and write.

**A malformed movement is answered with fields, not a 403.** Which of the three
recording abilities a submission needs depends on its `type`, and reading that
from raw input in `StoreInventoryStockMovementRequest::authorize()` meant a
blank form or a misspelt type came back as `403` with nothing to correct. The
request now refuses only users who hold no recording ability at all; the
per-type ability is enforced by the controller from `validated('type')` once the
submission is well formed. Authorization is unchanged for a valid payload — a
storekeeper who may book stock in is still refused outright for a stock out or a
correction.

**A notification never follows the user into another college.** The session is
per user, not per college, so a flashed message composed from one college's
records was rendered on the next page opened in another: recording a receipt of
"Theirs Only" in college B put that name on college A's stock ledger, even
though every query behind the ledger is tenant-scoped. `App\Support\Tenancy\TenantFlash`
records which college owns the pending one-shot data (`success`, `error`,
`warning`, `info`, `status`, `errors`, `_old_input`) and `ResolveTenant` drops it
when a request is served under a different one. Nothing persistent is touched,
and a deliberate college switch stamps the new college itself so its own
confirmation still appears.

## Tests

`tests/Feature/Inventory/InventoryPurchaseOrderTest`,
`InventoryStockMovementTest` and `InventoryPhase2SeederTest` cover the
lifecycle, server-computed totals, tenant-safe vendors/items, number
uniqueness (including reuse after soft delete), partial receipts, over-receipt
refusal, negative-stock refusal, ledger immutability, isolation, per-ability
RBAC and the item-form adjustment hook. `InventoryNavigationTest` and
`InventoryModuleSeederTest` were updated: the section now pins six entries, and
only the phases that are still unbuilt are asserted absent.
`InventorySchemaRelationshipTest` pins the composite foreign keys and their
parent keys, and `tests/Feature/Tenancy/TenantFlashIsolationTest` pins that
one-shot session data does not cross a tenant boundary.
