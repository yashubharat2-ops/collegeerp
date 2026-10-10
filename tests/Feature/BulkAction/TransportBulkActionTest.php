<?php

namespace Tests\Feature\BulkAction;

use App\Models\College;
use App\Models\Faculty;
use App\Models\StudentTransportAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\TransportDriver;
use App\Models\TransportFeeStructure;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Support\Str;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

/**
 * Transport bulk actions — selection, export authorization, policy enforcement,
 * tenant isolation and the export-only guarantee.
 *
 * The Transport family is deliberately EXPORT-ONLY, and these tests pin it down:
 * every Transport module registers exactly one action (`export`), the CSV
 * endpoint re-queries the ticked ids inside the active college and
 * re-authorizes the module and the record, a hand-edited URL cannot widen a
 * download, and nothing bulk-assigns, collects or mutates. The
 * vehicle-document CSV is metadata only: the private server path
 * (`file_path`) must never appear in a download — the file itself is still
 * served exclusively by the authorized download route. The driver CSV never
 * carries the license number (a government identity document number).
 */
class TransportBulkActionTest extends TestCase
{
    use StudentTestHelpers;

    /**
     * Every Transport module, with the model its export handler operates on, the
     * permission that gates it and the per-record policy ability.
     *
     * @return array<string, array{model: class-string, permission: string, policy: ?string}>
     */
    private function transportModules(): array
    {
        return [
            'vehicles' => ['model' => Vehicle::class, 'permission' => 'vehicles.view', 'policy' => 'view'],
            'transport_drivers' => ['model' => TransportDriver::class, 'permission' => 'transport_drivers.view', 'policy' => 'view'],
            'transport_routes' => ['model' => TransportRoute::class, 'permission' => 'transport_routes.view', 'policy' => 'view'],
            'transport_stops' => ['model' => TransportStop::class, 'permission' => 'transport_routes.view', 'policy' => 'view'],
            'vehicle_documents' => ['model' => VehicleDocument::class, 'permission' => 'vehicle_documents.view', 'policy' => 'view'],
            'student_transport_assignments' => ['model' => StudentTransportAssignment::class, 'permission' => 'student_transport_assignments.view', 'policy' => 'view'],
            'transport_fees' => ['model' => StudentTransportFeeAssignment::class, 'permission' => 'transport_fees.view', 'policy' => 'view'],
        ];
    }

    /**
     * The transport masters keep college_id out of $fillable (the tenant
     * context stamps it), so fixtures assign it explicitly.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $attributes
     */
    private function master(string $class, College $college, array $attributes)
    {
        $record = new $class($attributes);
        $record->college_id = $college->id;
        $record->save();

        return $record;
    }

    private function makeVehicle(College $college, string $registration): Vehicle
    {
        return $this->master(Vehicle::class, $college, [
            'registration_number' => $registration,
            'vehicle_type' => 'Bus',
            'make' => 'Ashok Leyland',
            'model' => 'Cheetah',
            'seating_capacity' => 40,
            'status' => 'active',
        ]);
    }

    private function makeRoute(College $college, string $code): TransportRoute
    {
        return $this->master(TransportRoute::class, $college, [
            'name' => 'Route '.$code,
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeStop(College $college, TransportRoute $route, string $code, int $sequence = 1): TransportStop
    {
        // TransportStop keeps route_id out of $fillable (the master screen owns
        // the route), so it is assigned as a property — the same convention the
        // existing Transport fixtures use. transport_stops.route_id is NOT NULL
        // with a composite FK to transport_routes.
        $stop = new TransportStop([
            'name' => 'Stop '.$code,
            'code' => $code,
            'sequence' => $sequence,
            'pickup_time' => '08:15',
            'drop_time' => '17:30',
            'status' => 'active',
        ]);
        $stop->college_id = $college->id;
        $stop->route_id = $route->id;
        $stop->save();

        return $stop;
    }

    private function makeDriver(College $college, string $licenseNumber): TransportDriver
    {
        // transport_drivers.faculty_id is NOT NULL with a faculties FK: a driver
        // is always an existing staff member, so create one first.
        $staff = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'EMP-'.strtoupper(Str::random(6)),
            'first_name' => 'Driver',
            'last_name' => 'Staff',
            'status' => 'active',
        ]);

        return $this->master(TransportDriver::class, $college, [
            'faculty_id' => $staff->id,
            'license_number' => $licenseNumber,
            'license_type' => 'Heavy passenger',
            'license_expiry' => '2028-12-31',
            'joining_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    public function test_every_transport_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->transportModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "Transport module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertSame($spec['policy'], $handler->policyAbility(), $module);
        }
    }

    public function test_transport_listings_expose_bulk_selection_controls(): void
    {
        $college = $this->makeCollege('TRUI');
        $manager = $this->makeUserWithPermissions($college, [
            'vehicles.view', 'transport_drivers.view', 'transport_routes.view',
            'vehicle_documents.view', 'student_transport_assignments.view', 'transport_fees.view',
        ]);

        $vehicle = $this->makeVehicle($college, 'KA-01-AB-1234');
        $route = $this->makeRoute($college, 'NORTH');
        $stop = $this->makeStop($college, $route, 'GATE');
        $driver = $this->makeDriver($college, 'DL-UI-0001');
        $document = $this->master(VehicleDocument::class, $college, [
            'vehicle_id' => $vehicle->id,
            'document_type' => 'registration',
            'document_number' => 'RC-UI-001',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'file_path' => 'vehicle-documents/'.$college->id.'/ui-secret.pdf',
            'original_filename' => 'rc-ui.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
            'uploaded_by' => $manager->id,
        ]);

        $year = $this->makeYear($college);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $this->makeProgram($college));
        $assignment = StudentTransportAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-06-01',
            'status' => StudentTransportAssignment::STATUS_ACTIVE,
        ]);
        $structure = TransportFeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'City Route Fee',
            'code' => 'TRF-UI-001',
            'amount' => 1200.50,
            'effective_from' => '2026-07-01',
            'status' => 'active',
        ]);
        $fee = StudentTransportFeeAssignment::create([
            'college_id' => $college->id,
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id,
            'amount' => 1200.50,
            'effective_from' => '2026-07-01',
            'status' => StudentTransportFeeAssignment::STATUS_ACTIVE,
        ]);

        $pages = [
            ['route' => 'vehicles.index', 'module' => 'vehicles', 'needle' => 'KA-01-AB-1234'],
            ['route' => 'transport-drivers.index', 'module' => 'transport_drivers', 'needle' => 'Heavy passenger'],
            ['route' => 'transport-routes.index', 'module' => 'transport_routes', 'needle' => 'NORTH'],
            ['route' => 'transport-stops.list', 'module' => 'transport_stops', 'needle' => 'GATE'],
            ['route' => 'vehicle-documents.index', 'module' => 'vehicle_documents', 'needle' => 'rc-ui.pdf'],
            ['route' => 'transport-assignments.index', 'module' => 'student_transport_assignments', 'needle' => $enrollment->enrollment_number],
            ['route' => 'transport-fees.index', 'module' => 'transport_fees', 'needle' => 'City Route Fee'],
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

    public function test_transport_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('TRPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $vehicle = $this->makeVehicle($college, 'KA-01-PERM-1');

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'vehicles',
            'action' => 'export',
            'ids' => [$vehicle->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('vehicles.export', ['ids' => [$vehicle->id]]))
            ->assertForbidden();
    }

    public function test_transport_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('TRA');
        $collegeB = $this->makeCollege('TRB');
        $manager = $this->makeUserWithPermissions($collegeA, ['transport_routes.view']);

        $mine = $this->makeRoute($collegeA, 'MINE');
        $foreign = $this->makeRoute($collegeB, 'FOREIGN');

        $response = $this->asCollege($collegeA, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'transport_routes',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertSame([$mine->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($collegeA, $manager)->get($redirect)->assertOk();
        $this->assertStringContainsString('transport-routes-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('MINE', $body);
        $this->assertStringNotContainsString('FOREIGN', $body);
    }

    public function test_transport_deleted_and_nonexistent_ids_are_skipped(): void
    {
        $college = $this->makeCollege('TRDEL');
        $manager = $this->makeUserWithPermissions($college, ['vehicles.view']);

        $live = $this->makeVehicle($college, 'KA-01-LIVE-1');
        $deleted = $this->makeVehicle($college, 'KA-01-GONE-1');
        $deleted->delete();

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'vehicles',
            'action' => 'export',
            'ids' => [$live->id, $deleted->id, 999999],
        ])->assertOk();

        $this->assertSame([$live->id], $response->json('data.ids'));
        $this->assertSame(2, $response->json('skipped_unauthorized'));

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString('KA-01-LIVE-1', $body);
        $this->assertStringNotContainsString('KA-01-GONE-1', $body);
    }

    public function test_vehicle_export_streams_a_bom_prefixed_csv(): void
    {
        $college = $this->makeCollege('TRCSV');
        $manager = $this->makeUserWithPermissions($college, ['vehicles.view']);
        $vehicle = $this->makeVehicle($college, 'KA-01-CSV-1234');

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'vehicles',
            'action' => 'export',
            'ids' => [$vehicle->id],
        ])->assertOk();

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'));

        $csv->assertOk();
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('vehicles-export-', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));

        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Every CSV download leads with the UTF-8 BOM.');
        $this->assertStringContainsString('KA-01-CSV-1234', $body);
    }

    public function test_vehicle_document_export_carries_metadata_but_never_the_private_path(): void
    {
        $college = $this->makeCollege('TRDOC');
        $manager = $this->makeUserWithPermissions($college, ['vehicle_documents.view']);
        $vehicle = $this->makeVehicle($college, 'KA-01-DOC-1');
        $document = $this->master(VehicleDocument::class, $college, [
            'vehicle_id' => $vehicle->id,
            'document_type' => 'insurance',
            'document_number' => 'POL-TR-001',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'file_path' => 'vehicle-documents/'.$college->id.'/tr-secret-insurance.pdf',
            'original_filename' => 'insurance-tr.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'uploaded_by' => $manager->id,
        ]);

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'vehicle_documents',
            'action' => 'export',
            'ids' => [$document->id],
        ])->assertOk();

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($college, $manager)->get($redirect)->assertOk();
        $this->assertStringContainsString('vehicle-documents-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        // Metadata the listing shows: vehicle, type, number, dates, validity,
        // original file name and size.
        $this->assertStringContainsString('KA-01-DOC-1', $body);
        $this->assertStringContainsString('POL-TR-001', $body);
        $this->assertStringContainsString('insurance-tr.pdf', $body);

        // ...and never the private path, the storage directory or the stored
        // file name — the file itself streams through the download route only.
        $this->assertStringNotContainsString($document->file_path, $body);
        $this->assertStringNotContainsString('vehicle-documents/'.$college->id.'/', $body);
        $this->assertStringNotContainsString('tr-secret-insurance.pdf', $body);
    }

    public function test_driver_export_never_carries_the_license_number(): void
    {
        $college = $this->makeCollege('TRLIC');
        $manager = $this->makeUserWithPermissions($college, ['transport_drivers.view']);
        $driver = $this->makeDriver($college, 'DL-TR-SECRET-9999');

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'transport_drivers',
            'action' => 'export',
            'ids' => [$driver->id],
        ])->assertOk();

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();

        // The license TYPE and EXPIRY are listing columns and export fine; the
        // license NUMBER is a government identity document number and must
        // never leave the server in a CSV.
        $this->assertStringContainsString('Heavy passenger', $body);
        $this->assertStringContainsString('2028-12-31', $body);
        $this->assertStringNotContainsString('DL-TR-SECRET-9999', $body);
    }

    public function test_transport_exports_never_mutate_assignments_or_fees(): void
    {
        $college = $this->makeCollege('TRNOMUT');
        $manager = $this->makeUserWithPermissions($college, [
            'vehicles.view', 'transport_routes.view', 'student_transport_assignments.view', 'transport_fees.view',
        ]);

        $vehicle = $this->makeVehicle($college, 'KA-01-NOMUT-1');
        $route = $this->makeRoute($college, 'NOMUT');
        $stop = $this->makeStop($college, $route, 'NOMUT-STOP');
        $year = $this->makeYear($college);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $this->makeProgram($college));
        $assignment = StudentTransportAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-06-01',
            'status' => StudentTransportAssignment::STATUS_ACTIVE,
        ]);
        $structure = TransportFeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Nomut Fee',
            'code' => 'TRF-NOMUT',
            'amount' => 900,
            'effective_from' => '2026-07-01',
            'status' => 'active',
        ]);
        $fee = StudentTransportFeeAssignment::create([
            'college_id' => $college->id,
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id,
            'amount' => 900,
            'effective_from' => '2026-07-01',
            'status' => StudentTransportFeeAssignment::STATUS_ACTIVE,
        ]);

        $before = [
            'vehicle' => $vehicle->fresh()->getAttributes(),
            'route' => $route->fresh()->getAttributes(),
            'assignment' => $assignment->fresh()->getAttributes(),
            'fee' => $fee->fresh()->getAttributes(),
        ];

        foreach ([
            ['vehicles', $vehicle->id],
            ['transport_routes', $route->id],
            ['student_transport_assignments', $assignment->id],
            ['transport_fees', $fee->id],
        ] as [$module, $id]) {
            $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$id],
            ])->assertOk();

            $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        }

        // Read-only by construction: no assignment completed/cancelled, no fee
        // collected, no master archived.
        $this->assertSame($before['vehicle'], $vehicle->fresh()->getAttributes());
        $this->assertSame($before['route'], $route->fresh()->getAttributes());
        $this->assertSame($before['assignment'], $assignment->fresh()->getAttributes());
        $this->assertSame($before['fee'], $fee->fresh()->getAttributes());
        $this->assertSame(StudentTransportAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertSame(StudentTransportFeeAssignment::STATUS_ACTIVE, $fee->fresh()->status);
    }
}
