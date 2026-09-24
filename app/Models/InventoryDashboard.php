<?php

namespace App\Models;

/**
 * InventoryDashboard — the read-only Inventory / Asset Management overview.
 *
 * Deliberately NOT an Eloquent model: there are no dashboard tables and no
 * summary rows. Every figure is aggregated live from the Phase 1 masters
 * (categories, items/assets, vendors), so nothing is duplicated.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * InventoryDashboardPolicy is registered against it and guards
 * `inventory_dashboard.view`.
 */
final class InventoryDashboard
{
}
