# Inventory / Asset Reports

## Overview

Inventory / Asset Reports is a read-only reporting layer over the existing Inventory / Asset Management module. It reuses the item and asset master, stock movement ledger, purchase orders and lines, issue records, asset assignment history, and maintenance work orders. It adds no report, receipt, asset, or workflow tables and does not change operational Inventory behavior.

Every report page is served by the GET-only `inventory-reports.index` route. The report selector inside the page presents these eleven reports in this fixed order:

1. Current Stock Report
2. Low Stock Report
3. Inventory Transaction Report
4. Stock Adjustment Report
5. Purchase / Goods Receipt Report
6. Item Issue / Allocation Report
7. Asset Register
8. Asset Assignment Report
9. Asset Return Report
10. Asset Maintenance Report
11. Inventory Summary

The application sidebar has one **Inventory / Asset Reports** link in the existing plain **REPORTS** section. Inventory / Asset Management remains the separate operational section; the reporting entry does not expose operational permissions or actions.

## Permission

The only permission for this reporting layer is `inventory_reports.view`. It is defined in the existing Inventory Phase 4 permission architecture, checked through the `InventoryItem` policy, and included in the central role-permission seed for Super Admin and College Admin. It does not imply permission to view or change the item master, issue stock, receive purchase orders, assign assets, record returns, or edit maintenance work orders.

## Reports and supported filters

Filter choices come from fields and relationships already present in the Inventory schema. Empty filters are ignored. ID filters accept positive integers; an ID belonging to another college safely matches no rows. Date ranges use `from` and `to` in `YYYY-MM-DD` format; when both are present, `to` cannot precede `from`.

| # | Report | Existing source and behavior | Supported filters |
|---|---|---|---|
| 1 | **Current Stock Report** | `inventory_items` joined to the grouped balance from `InventoryStockBalanceService`. Items with no ledger rows show zero. Inactive items remain visible and can be filtered by status; soft-deleted items are excluded. | Category, item, item type, item status, and search by name/code/serial. |
| 2 | **Low Stock Report** | The same ledger-derived balance used by Current Stock. Includes only active consumables whose balance is at or below the selected threshold. The default threshold is `5.00`; it is a query parameter, not a stored reorder level. | Category, consumable item, threshold, and search by name/code/serial. Threshold must be non-negative and within the existing decimal range. |
| 3 | **Inventory Transaction Report** | Immutable `inventory_stock_movements` rows, with existing item/category, purchase-order, and creator details. Archived items/categories may be named in historical rows; movements are never edited by the report. | Category, item, purchase order, vendor, transaction type, direction, movement date range. |
| 4 | **Stock Adjustment Report** | Existing adjustment and stock-out rows from `inventory_stock_movements`, matching the source used by the operational Stock Adjustment screen. Quantities and `balance_after` are displayed as stored. | Category, item, adjustment/stock-out type, direction, movement date range. |
| 5 | **Purchase / Goods Receipt Report** | Existing non-deleted purchase-order lines and stored PO values, plus incoming ledger rows of type `purchase_receipt` and manual `stock_in`. Receipt quantity/reference/balance are not recalculated and no receipt table is created. PO lines are filtered by PO date; receipt rows by movement date. | Vendor, purchase order, item, purchase status, date range. |
| 6 | **Item Issue / Allocation Report** | Existing append-only `inventory_issues` rows with their item, student/staff recipient, and creator. Issue history is not reconstructed from stock-outs; the linked stock-out remains in the authoritative movement ledger. The schema has no issue-status column, so none is invented. | Category, item, recipient type, issue movement date range. |
| 7 | **Asset Register** | `inventory_items` rows with `item_type = asset`, current active assignment, latest stored return date, latest completed maintenance date, and open-maintenance count. Inactive assets remain visible; soft-deleted assets do not. The schema has no physical-location field, so the report does not invent one. | Category, asset, asset status, current custody (assigned/unassigned), search by name/code/serial. |
| 8 | **Asset Assignment Report** | Existing `inventory_assignments` custody-history rows for assets, including both active and returned assignments. Re-assignment remains a separate history row. Archived asset labels may be shown; assignment rows themselves have no soft-delete lifecycle. | Category, asset, assignment status, assigned-person type, assigned-on date range. |
| 9 | **Asset Return Report** | Returned rows from `inventory_assignments`, including the stored return date, actor, and notes. There is no separate return table. | Category, asset, returned-on date range. |
| 10 | **Asset Maintenance Report** | Existing non-deleted `inventory_maintenances` work orders for assets, including stored type/status/dates/cost, optional vendor, performer, and description. Archived asset/vendor labels can remain visible in historical work. | Category, asset, vendor, maintenance status, maintenance type, scheduled-on date range. |
| 11 | **Inventory Summary** | Live counts and the existing category roll-up, plus current ledger stock grouped by unit. The category low-stock count uses active consumables at or below the selected threshold. Quantities of unlike units are never added together. Total catalogue/category counts include inactive, non-deleted rows and exclude soft-deleted rows. Purchase-order value is the stored total of non-deleted POs; no payment or currency value is inferred. | Category, low-stock threshold. |

### Filter values

- Item type: `consumable`, `asset`; item/asset status: `active`, `inactive`.
- Transaction type: `opening`, `purchase_receipt`, `stock_in`, `stock_out`, `adjustment`; direction: `in`, `out`.
- Purchase status: `draft`, `submitted`, `partially_received`, `received`, `cancelled`.
- Issue recipient and assignment person type: `student`, `faculty`.
- Assignment status: `active`, `returned`; maintenance status: `scheduled`, `in_progress`, `completed`.
- Maintenance type: `preventive`, `repair`, `inspection`, `calibration`, `other`.
- Search and filter values are validated only for controls supported by the selected report. Per-item reorder quantities and asset physical locations are not in the existing schema and are not added as filters or stored fields.

## Authoritative stock source

`InventoryStockBalanceService` is the sole balance source for Current Stock and Low Stock. It groups signed rows from `inventory_stock_movements` by college and item, then left-joins those balances to the item master so an item with no movements has a zero balance. It does not use the mutable catalogue quantity cache for a report balance.

Transactions and Stock Adjustment display the original immutable movement values, including the stored `balance_after`. Purchase / Goods Receipt and Summary also read receipt counts and current stock from that same ledger. Summary groups stock by unit; it never produces a misleading grand total across different units. The existing stock-writing services, forms, controllers, and ledger behavior are not changed by this reporting layer.

## Tenant isolation, lifecycle, and query behavior

- Every source query and filter-option list runs in the active college context through the existing college scopes. The stock-balance query also pins its grouped movement subquery to that college. The report accepts no `college_id` filter.
- Related items, vendors, purchase orders, categories, people, and history remain constrained by their existing tenant relationships. Supplying a foreign college's item, category, vendor, or purchase-order ID returns no matching rows; it never broadens the query.
- Standard soft-delete scopes exclude archived items from current catalogue reports, archived purchase orders from the PO-line list, and archived maintenance work orders from the maintenance list. Historical movements, assignments, returns, and work orders may still display an archived related item where the source history is retained. Inactive catalogue records are not silently removed from reports where the operational module retains them; Low Stock remains limited to active consumables by definition.
- Results are paginated at 20 rows per page and ordered deterministically. Related display data is eager-loaded; category, custody, service, and summary figures use grouped aggregates/counts rather than per-row queries.
- All reports are GET-only and read-only. There are no report-specific POST/PUT/PATCH/DELETE routes, export actions, or write-side effects.

## Schema-backed limitations

The Inventory schema does not define an item-specific minimum/reorder quantity, asset physical location, maintenance cost currency, or separate return/receipt report tables. Reports use the existing user-selected low-stock threshold, stored maintenance cost, asset assignment rows, and stock movement ledger instead of inventing those fields or duplicating business data.
