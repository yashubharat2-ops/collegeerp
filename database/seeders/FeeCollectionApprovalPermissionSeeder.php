<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Safe standalone deployment seeder for Fee Collection approval.
 *
 * Creates the single permission the central approval framework needs as the
 * approver capability for module `fees` / action `fee_collection`:
 *
 *   fee_collections.approve → finalize (or reject) a pending fee collection
 *
 * Like the other module permission seeders, it only CREATES permission rows: no
 * demo data and no existing role grants are changed. Run it on an existing
 * deployment before enabling the setting, then grant the permission to the
 * administrator role that should approve collections (in a fresh install
 * DatabaseSeeder already includes it in the College Admin / Super Admin role).
 */
class FeeCollectionApprovalPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'fee_collections.approve',
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
