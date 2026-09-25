<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Communication Management Phase 1 permissions.
 *
 * Safe standalone deployment seeder: creates the permissions idempotently and
 * changes no existing role grants or demo data. DatabaseSeeder spreads
 * PERMISSIONS into the centralized permission list, which grants them to the
 * seeded Super Admin and College Admin roles.
 *
 * Deliberately excludes future phases (SMS / e-mail / WhatsApp gateways,
 * templates, delivery logs, communication reports).
 */
class CommunicationPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'communication_dashboard.view',
        'notices.view', 'notices.create', 'notices.update', 'notices.delete', 'notices.publish',
        'circulars.view', 'circulars.create', 'circulars.update', 'circulars.delete', 'circulars.publish',
        'notifications.view', 'notifications.create', 'notifications.update', 'notifications.delete',
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
