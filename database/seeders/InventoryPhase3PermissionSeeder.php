<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Inventory / Asset Management Phase 3 permissions.
 *
 * Safe standalone deployment seeder: creates the permissions idempotently
 * and changes no existing role grants or demo data. DatabaseSeeder spreads
 * PERMISSIONS into the centralized permission list, which grants them to
 * the seeded Super Admin and College Admin roles.
 *
 * One permission family per module, and each menu is controlled only by its
 * own family (see the layout):
 *
 *   - Item Issue / Allocation → `inventory_issues.*`
 *   - Asset Assignment        → `inventory_assignments.*`
 *   - Asset Return            → `inventory_asset_returns.*`
 *   - Asset Maintenance       → `inventory_maintenance.*`
 *
 * Issue and assignment history are append-only, so there is no update or
 * delete permission for them — a return is not a delete (the assignment row
 * flips to `returned` and stays), and an issue is never undone (returned
 * stock is a new incoming movement). Maintenance is the only live-work
 * module, hence its `update`. Reports remain deliberately excluded (later
 * phase).
 */
class InventoryPhase3PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'inventory_issues.view', 'inventory_issues.create',
        'inventory_assignments.view', 'inventory_assignments.create',
        'inventory_asset_returns.view', 'inventory_asset_returns.create',
        'inventory_maintenance.view', 'inventory_maintenance.create', 'inventory_maintenance.update',
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
