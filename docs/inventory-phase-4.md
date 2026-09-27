# Inventory / Asset Management — Phase 4

All five read-only links sit **directly** in the existing single Inventory / Asset
Management sidebar section, after the Phase 1–3 links. Each has its own `.view`
permission; there are no write routes, new tables, or new asset/stock masters.

| Screen | Permission | Existing source |
| --- | --- | --- |
| Current Stock | `inventory_current_stock.view` | Signed sum of the stock ledger per item, including zero for items without movements |
| Low Stock | `inventory_low_stock.view` | Same ledger balances, active consumables at/below a selected threshold (default 5.00) |
| Asset Register | `inventory_asset_register.view` | Asset-type Items / Assets, active assignment, last return, last completed service and open maintenance |
| Stock / Transaction Reports | `inventory_stock_reports.view` | Ledger opening / in / out / transaction count / closing per item and movement-date range (including archived items with history) |
| Inventory Reports | `inventory_reports.view` | Category rollup of Items / Assets, ledger-based low stock, assigned assets and assets with open maintenance |

The low-stock threshold is a filter, **not** a persisted reorder point. Balances
and low-stock counts come from `inventory_stock_movements`, never from the
cached `inventory_items.quantity`; balances and report figures are never added
across unlike units. Assignments and maintenance are custody/work, not stock
movements. Tenant scoping applies to all source queries and joined ledger
balances are also explicitly joined on `college_id`. Drill-down links to other
modules appear only when the viewer has those modules' permissions.

Focused coverage: `InventoryPhase4Test`, `InventoryPhase4SeederTest` and the
updated `InventoryNavigationTest` plus existing Inventory seeder checks.
