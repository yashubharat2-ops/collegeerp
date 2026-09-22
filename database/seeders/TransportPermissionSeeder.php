<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Safe standalone deployment seeder: no demo data or existing role grants changed. */
class TransportPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'transport_dashboard.view',
        'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete',
        'transport_drivers.view', 'transport_drivers.create', 'transport_drivers.update', 'transport_drivers.delete',
        'transport_routes.view', 'transport_routes.create', 'transport_routes.update', 'transport_routes.delete',
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
