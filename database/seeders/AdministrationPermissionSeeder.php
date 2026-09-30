<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The centralized registry's Administration entries (not a second registry).
 * Safe on existing installations: no demo data, role grants, or activation
 * flags are overwritten. DatabaseSeeder uses this same list for fresh installs.
 */
class AdministrationPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.update', 'users.assign_roles',
        'roles.view', 'roles.create', 'roles.update',
        'permissions.view', 'settings.view', 'settings.update', 'audit_logs.view',
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
