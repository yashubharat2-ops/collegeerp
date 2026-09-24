<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Inventory / Asset Management Phase 1 permissions.
 *
 * Safe standalone deployment seeder: creates the permissions idempotently and
 * changes no existing role grants or demo data. DatabaseSeeder spreads
 * PERMISSIONS into the centralized permission list, which grants them to the
 * seeded Super Admin and College Admin roles.
 *
 * Deliberately excludes later phases (purchase orders, stock in/out,
 * issue/return, asset assignment, maintenance, reports).
 */
class InventoryPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'inventory_dashboard.view',
        'inventory_categories.view', 'inventory_categories.create', 'inventory_categories.update', 'inventory_categories.delete',
        'inventory_items.view', 'inventory_items.create', 'inventory_items.update', 'inventory_items.delete',
        'inventory_vendors.view', 'inventory_vendors.create', 'inventory_vendors.update', 'inventory_vendors.delete',
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
