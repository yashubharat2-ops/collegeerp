<?php

namespace Tests\Feature\BulkAction;

use App\Models\College;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelFeeAssignment;
use App\Models\HostelRoom;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use Tests\Feature\Hostel\HostelTestHelpers;
use Tests\TestCase;

/**
 * Hostel bulk actions — selection, export authorization, policy enforcement,
 * tenant isolation and the export-only guarantee.
 *
 * The Hostel family is deliberately EXPORT-ONLY, and these tests pin it down:
 * every Hostel module registers exactly one action (`export`), the CSV
 * endpoint re-queries the ticked ids inside the active college and
 * re-authorizes the module and the record, a hand-edited URL cannot widen a
 * download, and nothing bulk-allocates, vacates, collects or marks attendance.
 * The listing's own bulk attendance MARKING screen is a mutation workflow and
 * is not touched by these exports.
 */
class HostelBulkActionTest extends TestCase
{
    use HostelTestHelpers;

    /**
     * Every Hostel module, with the model its export handler operates on, the
     * permission that gates it and the per-record policy ability.
     *
     * @return array<string, array{model: class-string, permission: string, policy: ?string}>
     */
    private function hostelModules(): array
    {
        return [
            'hostels' => ['model' => Hostel::class, 'permission' => 'hostels.view', 'policy' => 'view'],
            'hostel_buildings' => ['model' => HostelBuilding::class, 'permission' => 'hostel_buildings.view', 'policy' => 'view'],
            'hostel_rooms' => ['model' => HostelRoom::class, 'permission' => 'hostel_rooms.view', 'policy' => 'view'],
            'hostel_beds' => ['model' => HostelBed::class, 'permission' => 'hostel_beds.view', 'policy' => 'view'],
            'hostel_allocations' => ['model' => HostelAllocation::class, 'permission' => 'hostel_allocations.view', 'policy' => 'view'],
            'hostel_fees' => ['model' => HostelFeeAssignment::class, 'permission' => 'hostel_fees.view', 'policy' => 'view'],
            'hostel_attendance' => ['model' => HostelAttendance::class, 'permission' => 'hostel_attendance.view', 'policy' => 'view'],
        ];
    }

    public function test_every_hostel_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->hostelModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "Hostel module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertSame($spec['policy'], $handler->policyAbility(), $module);
        }
    }

    public function test_hostel_listings_expose_bulk_selection_controls(): void
    {
        $college = $this->makeCollege('HOUI');
        $manager = $this->makeUserWithPermissions($college, [
            'hostels.view', 'hostel_buildings.view', 'hostel_rooms.view', 'hostel_beds.view',
            'hostel_allocations.view', 'hostel_fees.view', 'hostel_attendance.view',
        ]);

        $hostel = $this->makeHostel($college, ['code' => 'H-UI-01']);
        $building = $this->makeHostelBuilding($college, $hostel, ['code' => 'B-UI-01']);
        $room = $this->makeHostelRoom($college, $building, ['room_number' => 'UI-101']);
        $bed = $this->makeHostelBed($college, $room, ['bed_number' => 7]);
        $allocation = $this->makeHostelAllocation($college, null, $bed);
        $fee = $this->makeHostelFeeAssignment($college, $allocation);
        $attendance = HostelAttendance::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $allocation->student_enrollment_id,
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => HostelAttendance::STATUS_PRESENT,
            'remarks' => 'On time',
            'marked_at' => now(),
            'marked_by' => $manager->id,
        ]);

        // Fixtures run outside an HTTP request, where CollegeScope matches no
        // rows — resolve the display names through scope-free lookups.
        $enrollmentNumber = \App\Models\StudentEnrollment::withoutGlobalScopes()
            ->findOrFail($allocation->student_enrollment_id)->enrollment_number;
        $structureName = \App\Models\HostelFeeStructure::withoutGlobalScopes()
            ->findOrFail($fee->hostel_fee_structure_id)->name;

        $pages = [
            ['route' => 'hostels.index', 'module' => 'hostels', 'needle' => 'H-UI-01'],
            ['route' => 'hostel-buildings.index', 'module' => 'hostel_buildings', 'needle' => 'B-UI-01'],
            ['route' => 'hostel-rooms.index', 'module' => 'hostel_rooms', 'needle' => 'UI-101'],
            ['route' => 'hostel-beds.index', 'module' => 'hostel_beds', 'needle' => '7'],
            ['route' => 'hostel-allocations.index', 'module' => 'hostel_allocations', 'needle' => $enrollmentNumber],
            ['route' => 'hostel-fees.index', 'module' => 'hostel_fees', 'needle' => $structureName],
            ['route' => 'hostel-attendance.index', 'module' => 'hostel_attendance', 'needle' => 'On time'],
        ];

        foreach ($pages as $page) {
            $response = $this->asCollege($college, $manager)->get(route($page['route']))->assertOk();

            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$page['module'].'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            $response->assertSee($page['needle']);
        }
    }

    public function test_hostel_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('HOPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $hostel = $this->makeHostel($college, ['code' => 'H-PERM-01']);

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'hostels',
            'action' => 'export',
            'ids' => [$hostel->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('hostels.export', ['ids' => [$hostel->id]]))
            ->assertForbidden();
    }

    public function test_hostel_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('HOA');
        $collegeB = $this->makeCollege('HOB');
        $manager = $this->makeUserWithPermissions($collegeA, ['hostels.view']);

        $mine = $this->makeHostel($collegeA, ['code' => 'H-MINE-01']);
        $foreign = $this->makeHostel($collegeB, ['code' => 'H-FOREIGN-01']);

        $response = $this->asCollege($collegeA, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'hostels',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertSame([$mine->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($collegeA, $manager)->get($redirect)->assertOk();
        $this->assertStringContainsString('hostels-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('H-MINE-01', $body);
        $this->assertStringNotContainsString('H-FOREIGN-01', $body);
    }

    public function test_hostel_deleted_and_nonexistent_ids_are_skipped(): void
    {
        $college = $this->makeCollege('HODEL');
        $manager = $this->makeUserWithPermissions($college, ['hostel_beds.view']);

        $live = $this->makeHostelBed($college, null, ['bed_number' => 11]);
        $deleted = $this->makeHostelBed($college, null, ['bed_number' => 12]);
        $deleted->delete();

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'hostel_beds',
            'action' => 'export',
            'ids' => [$live->id, $deleted->id, 999999],
        ])->assertOk();

        $this->assertSame([$live->id], $response->json('data.ids'));
        $this->assertSame(2, $response->json('skipped_unauthorized'));

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString('11', $body);
        $this->assertStringNotContainsString('12', $body);
    }

    public function test_hostel_fee_export_streams_a_bom_prefixed_csv_with_live_ledger(): void
    {
        $college = $this->makeCollege('HOCSV');
        $manager = $this->makeUserWithPermissions($college, ['hostel_fees.view']);
        $fee = $this->makeHostelFeeAssignment($college);

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'hostel_fees',
            'action' => 'export',
            'ids' => [$fee->id],
        ])->assertOk();

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'));

        $csv->assertOk();
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('hostel-fees-export-', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));

        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Every CSV download leads with the UTF-8 BOM.');

        // The stored amount and the live ledger position (nothing collected yet).
        $this->assertStringContainsString(number_format((float) $fee->assigned_amount, 2, '.', ''), $body);
        $this->assertStringContainsString('0.00', $body);
    }

    public function test_hostel_exports_never_mutate_allocations_fees_or_attendance(): void
    {
        $college = $this->makeCollege('HONOMUT');
        $manager = $this->makeUserWithPermissions($college, [
            'hostels.view', 'hostel_allocations.view', 'hostel_fees.view', 'hostel_attendance.view',
        ]);

        $hostel = $this->makeHostel($college, ['code' => 'H-NOMUT-01']);
        $allocation = $this->makeHostelAllocation($college);
        $fee = $this->makeHostelFeeAssignment($college, $allocation);
        $attendance = HostelAttendance::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $allocation->student_enrollment_id,
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => HostelAttendance::STATUS_ABSENT,
            'remarks' => 'Sick leave',
            'marked_at' => now(),
            'marked_by' => $manager->id,
        ]);

        $before = [
            'hostel' => $hostel->fresh()->getAttributes(),
            'allocation' => $allocation->fresh()->getAttributes(),
            'fee' => $fee->fresh()->getAttributes(),
            'attendance' => $attendance->fresh()->getAttributes(),
        ];

        foreach ([
            ['hostels', $hostel->id],
            ['hostel_allocations', $allocation->id],
            ['hostel_fees', $fee->id],
            ['hostel_attendance', $attendance->id],
        ] as [$module, $id]) {
            $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$id],
            ])->assertOk();

            $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        }

        // Read-only by construction: no allocation vacated/cancelled, no fee
        // collected, no attendance marked or corrected, no master changed.
        $this->assertSame($before['hostel'], $hostel->fresh()->getAttributes());
        $this->assertSame($before['allocation'], $allocation->fresh()->getAttributes());
        $this->assertSame($before['fee'], $fee->fresh()->getAttributes());
        $this->assertSame($before['attendance'], $attendance->fresh()->getAttributes());
        $this->assertSame(HostelAllocation::STATUS_ACTIVE, $allocation->fresh()->status);
        $this->assertSame(HostelFeeAssignment::STATUS_ACTIVE, $fee->fresh()->status);
        $this->assertSame(HostelAttendance::STATUS_ABSENT, $attendance->fresh()->attendance_status);
    }
}
