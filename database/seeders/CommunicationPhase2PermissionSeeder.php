<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Communication Management Phase 2 permissions (templates, logs, delivery /
 * read tracking, reports).
 *
 * Safe standalone deployment seeder: creates the seven permissions
 * idempotently and changes no existing role grants or demo data. The
 * centralized DatabaseSeeder spreads PERMISSIONS into its permission list,
 * which grants them to the seeded Super Admin and College Admin roles.
 *
 * Logs are immutable from the UI, so no `communication_logs.create/update/
 * delete` permission exists; tracking and reports are read-only screens.
 * Later-phase permissions (external SMS / e-mail / WhatsApp gateways) are
 * deliberately excluded.
 */
class CommunicationPhase2PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'communication_templates.view', 'communication_templates.create', 'communication_templates.update', 'communication_templates.delete',
        'communication_logs.view',
        'communication_tracking.view',
        'communication_reports.view',
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
