<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Inventory / Asset Management Phase 2 permissions (purchase orders and stock).
 *
 * Safe standalone deployment seeder: creates the nine permissions idempotently
 * and changes no existing role grants or demo data. The centralized
 * DatabaseSeeder spreads PERMISSIONS into its permission list, which grants
 * them to the seeded Super Admin and College Admin roles.
 *
 * `inventory_purchase_orders.receive` is separate from update: booking goods
 * in changes stock, so it can be granted on its own. Stock movements are
 * immutable, so there is no update or delete permission — a correction is a
 * new movement, which is what `inventory_stock.adjust` grants.
 *
 * Later phases (issue/return to staff, asset assignment, maintenance,
 * reports) are deliberately excluded.
 */
class InventoryPhase2PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'inventory_purchase_orders.view', 'inventory_purchase_orders.create', 'inventory_purchase_orders.update', 'inventory_purchase_orders.delete', 'inventory_purchase_orders.receive',
        // Legacy Phase 2 stock permissions (kept for backward compatibility; new modules reuse the same ledger).
        'inventory_stock.view', 'inventory_stock.in', 'inventory_stock.out', 'inventory_stock.adjust',
        // Final Phase 2 structure: Purchase & Stock — 4 modules.
        'inventory_goods_receipts.view', 'inventory_goods_receipts.create',
        'inventory_stock_adjustments.view', 'inventory_stock_adjustments.create',
        'inventory_transactions.view',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug) {
            Permission::firstOrCreate(['slug' => $slug], [
                'name' => Str::headline($slug),
                'module' => Str::before($slug, '.'),
                'action' => Str::after($slug, '.'),
            ]);
        }
    }
}
