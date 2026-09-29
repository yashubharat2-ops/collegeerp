<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Five independent Phase 4 view permissions, including the shared Inventory / Asset Reports entry. */
class InventoryPhase4PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'inventory_current_stock.view',
        'inventory_low_stock.view',
        'inventory_asset_register.view',
        'inventory_stock_reports.view',
        'inventory_reports.view',
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
