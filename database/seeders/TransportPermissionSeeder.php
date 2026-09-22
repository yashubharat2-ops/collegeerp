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
        // Transport Phase 2 — Vehicle Documents.
        'vehicle_documents.view', 'vehicle_documents.create', 'vehicle_documents.update', 'vehicle_documents.delete',
        'transport_drivers.view', 'transport_drivers.create', 'transport_drivers.update', 'transport_drivers.delete',
        'transport_routes.view', 'transport_routes.create', 'transport_routes.update', 'transport_routes.delete',
        // Transport Phase 2 — Student Transport Assignment.
        'student_transport_assignments.view', 'student_transport_assignments.create', 'student_transport_assignments.update', 'student_transport_assignments.delete',
        // Transport Phase 2 — Transport Fees (structures + student assignments).
        'transport_fees.view', 'transport_fees.create', 'transport_fees.update', 'transport_fees.delete',
        // Transport Phase 2 — Transport Reports (read-only).
        'transport_reports.view',
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
