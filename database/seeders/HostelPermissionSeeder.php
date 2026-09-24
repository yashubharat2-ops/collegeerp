<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Safe standalone deployment seeder: no demo data or existing role grants changed. */
class HostelPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'hostel_dashboard.view',
        'hostels.view', 'hostels.create', 'hostels.update', 'hostels.delete',
        'hostel_buildings.view', 'hostel_buildings.create', 'hostel_buildings.update', 'hostel_buildings.delete',
        'hostel_rooms.view', 'hostel_rooms.create', 'hostel_rooms.update', 'hostel_rooms.delete',
        'hostel_beds.view', 'hostel_beds.create', 'hostel_beds.update', 'hostel_beds.delete',
        // Hostel Management Phase 2 — Allocation and Fees.
        'hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update', 'hostel_allocations.delete',
        'hostel_fees.view', 'hostel_fees.create', 'hostel_fees.update', 'hostel_fees.delete', 'hostel_fees.collect',
        // Hostel Management Phase 3 — Attendance and read-only Reports.
        'hostel_attendance.view', 'hostel_attendance.create', 'hostel_attendance.update', 'hostel_attendance.delete',
        'hostel_reports.view',
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
