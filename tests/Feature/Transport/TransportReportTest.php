<?php

namespace Tests\Feature\Transport;

use App\Models\{AcademicYear, College, Permission, Role, Student, StudentEnrollment, StudentTransportAssignment, StudentTransportFeeAssignment, TransportDriver, TransportFeeStructure, TransportReport, TransportRoute, TransportStop, User, Vehicle, VehicleDocument};
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transport Phase 2 — Transport Reports: READ-ONLY aggregates derived live
 * from the existing Transport / Student / Finance records. No report tables,
 * no writes, tenant scoped and deterministically ordered.
 */
class TransportReportTest extends TestCase
{
    private const REPORTS = [
        'vehicle_summary', 'vehicle_status', 'document_expiry', 'driver_summary', 'route_summary',
        'stop_students', 'assignments', 'fee_summary', 'fee_outstanding', 'active_inactive',
    ];

    private function college(string $code): College
    {
        return College::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'status' => 'active']);
    }

    private function user(College $college, ?array $permissions = null): User
    {
        $user = User::create(['name' => 'Report Staff', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $role = Role::create(['college_id' => $college->id, 'name' => 'Reports', 'slug' => (string) Str::uuid(), 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions ?? ['transport_reports.view'])->pluck('id'));
        $user->roles()->attach($role->id, ['college_id' => $college->id]);
        return $user;
    }

    private function login(College $college, ?array $permissions = null): User
    {
        $user = $this->user($college, $permissions);
        $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
        return $user;
    }

    private function fixture(string $class, College $college, array $data)
    {
        $record = new $class(array_diff_key($data, ['route_id' => true]));
        $record->college_id = $college->id;
        if (isset($data['route_id'])) {
            $record->route_id = $data['route_id'];
        }
        $record->save();
        return $record;
    }

    private function year(College $college): AcademicYear
    {
        return $this->fixture(AcademicYear::class, $college, [
            'name' => 'Year '.Str::upper(Str::random(4)), 'code' => Str::upper(Str::random(6)),
            'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'active',
        ]);
    }

    private function vehicle(College $college, string $registration, string $status = 'active'): Vehicle
    {
        return $this->fixture(Vehicle::class, $college, ['registration_number' => $registration, 'seating_capacity' => 40, 'status' => $status]);
    }

    private function document(College $college, Vehicle $vehicle, string $expiry): VehicleDocument
    {
        return VehicleDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'vehicle_id' => $vehicle->id,
            'document_type' => 'insurance',
            'expiry_date' => $expiry,
            'file_path' => sprintf('vehicle-documents/%d/%d/%s.pdf', $college->id, $vehicle->id, Str::uuid()),
            'original_filename' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
        ]);
    }

    private function assignment(College $college, AcademicYear $year, TransportRoute $route, TransportStop $stop, string $status = 'active', string $suffix = 'A'): StudentTransportAssignment
    {
        $student = $this->fixture(Student::class, $college, [
            'student_number' => 'STU-'.$suffix, 'first_name' => 'R', 'last_name' => 'Student '.$suffix, 'status' => 'active',
        ]);
        $enrollment = $this->fixture(StudentEnrollment::class, $college, [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-'.$suffix, 'enrollment_date' => now()->toDateString(), 'status' => 'active',
        ]);
        return $this->fixture(StudentTransportAssignment::class, $college, [
            'student_enrollment_id' => $enrollment->id, 'academic_year_id' => $year->id,
            'transport_route_id' => $route->id, 'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01', 'status' => $status,
        ]);
    }

    public function test_reports_are_permission_gated_read_only_and_all_render(): void
    {
        $a = $this->college('RPT');
        $this->login($a, []);
        $this->get(route('transport-reports.index'))->assertForbidden();

        // The screen is read-only: there is no POST route at all.
        $this->post(route('transport-reports.index'), [])->assertStatus(405);

        $this->login($a, ['transport_reports.view']);
        foreach (self::REPORTS as $report) {
            $this->get(route('transport-reports.index', ['report' => $report]))->assertOk();
        }
        // Unknown report keys fall back to the first report instead of erroring.
        $this->get(route('transport-reports.index', ['report' => 'bogus']))->assertOk();
    }

    public function test_vehicle_and_document_reports_derive_live_status(): void
    {
        $a = $this->college('RPTV');
        $active = $this->vehicle($a, 'KA-01 AA-1111', 'active');
        $this->vehicle($a, 'KA-01 BB-2222', 'maintenance');
        $expired = $this->document($a, $active, now()->subDays(5)->toDateString());
        $expiring = $this->document($a, $active, now()->addDays(10)->toDateString());
        $far = $this->document($a, $active, now()->addDays(200)->toDateString());
        $this->login($a);

        $this->get(route('transport-reports.index', ['report' => 'vehicle_status']))
            ->assertViewHas('statusReport', fn ($rows) => $rows == [
                ['status' => 'active', 'vehicles' => 1],
                ['status' => 'maintenance', 'vehicles' => 1],
            ]);

        // Nearest expiry first, expired ones included (they need attention).
        $this->get(route('transport-reports.index', ['report' => 'document_expiry']))
            ->assertViewHas('documentReport', fn ($rows) => $rows->pluck('id')->all() === [$expired->id, $expiring->id, $far->id]);

        // Vehicle summary carries live document counts (deterministic order).
        $this->get(route('transport-reports.index', ['report' => 'vehicle_summary']))
            ->assertViewHas('vehicleReport', fn ($rows) => $rows->pluck('registration_number')->all() === ['KA-01 AA-1111', 'KA-01 BB-2222'])
            ->assertViewHas('vehicleReport', fn ($rows) => $rows[0]->documents_count === 3 && $rows[0]->expiring_documents_count === 1);

        // Filters narrow the derivation.
        $this->get(route('transport-reports.index', ['report' => 'document_expiry', 'status' => 'expired']))
            ->assertViewHas('documentReport', fn ($rows) => $rows->pluck('id')->all() === [$expired->id]);
        $this->get(route('transport-reports.index', ['report' => 'vehicle_summary', 'vehicle_id' => $active->id]))
            ->assertViewHas('vehicleReport', fn ($rows) => $rows->count() === 1);
    }

    public function test_route_stop_and_assignment_reports_count_students(): void
    {
        $a = $this->college('RPTR');
        $year = $this->year($a);
        $route = $this->fixture(TransportRoute::class, $a, ['name' => 'North', 'code' => 'NORTH', 'status' => 'active']);
        $stop1 = $this->fixture(TransportStop::class, $a, ['route_id' => $route->id, 'name' => 'Gate', 'code' => 'GATE', 'sequence' => 1, 'status' => 'active']);
        $stop2 = $this->fixture(TransportStop::class, $a, ['route_id' => $route->id, 'name' => 'Mall', 'code' => 'MALL', 'sequence' => 2, 'status' => 'active']);
        $this->assignment($a, $year, $route, $stop1, 'active', 'A');
        $this->assignment($a, $year, $route, $stop1, 'active', 'B');
        $this->assignment($a, $year, $route, $stop2, 'completed', 'C');
        $this->login($a);

        $this->get(route('transport-reports.index', ['report' => 'route_summary']))
            ->assertViewHas('routeReport', fn ($rows) => count($rows) === 1 && $rows[0]['route']->stops_count === 2 && $rows[0]['students'] === 2);

        $this->get(route('transport-reports.index', ['report' => 'stop_students']))
            ->assertViewHas('stopReport', fn ($rows) => collect($rows)->map(fn ($row) => [$row['stop']->code, $row['active_students'], $row['total_assignments']])->all() === [
                ['GATE', 2, 2], ['MALL', 0, 1],
            ]);

        // Deterministic newest-first row order with working filters.
        $this->get(route('transport-reports.index', ['report' => 'assignments']))
            ->assertViewHas('assignmentReport', fn ($rows) => $rows->pluck('status')->all() === ['completed', 'active', 'active']);
        $this->get(route('transport-reports.index', ['report' => 'assignments', 'stop_id' => $stop1->id, 'status' => 'active']))
            ->assertViewHas('assignmentReport', fn ($rows) => $rows->count() === 2);
        $this->get(route('transport-reports.index', ['report' => 'active_inactive', 'status' => 'active']))
            ->assertViewHas('activeInactiveReport', fn ($data) => $data['rows']->count() === 2 && $data['counts'] === ['active' => 2, 'completed' => 1]);
    }

    public function test_fee_reports_match_the_shared_ledger(): void
    {
        $a = $this->college('RPTF');
        $year = $this->year($a);
        $route = $this->fixture(TransportRoute::class, $a, ['name' => 'R', 'code' => Str::upper(Str::random(6)), 'status' => 'active']);
        $stop = $this->fixture(TransportStop::class, $a, ['route_id' => $route->id, 'name' => 'S', 'code' => Str::upper(Str::random(6)), 'sequence' => 1, 'status' => 'active']);
        $assignment = $this->assignment($a, $year, $route, $stop, 'active', 'F');
        $this->login($a, ['transport_reports.view', 'transport_fees.view', 'transport_fees.create', 'transport_fees.update']);

        $structure = $this->fixture(TransportFeeStructure::class, $a, [
            'academic_year_id' => $year->id, 'name' => 'Fee', 'code' => Str::upper(Str::random(6)),
            'amount' => 1200.50, 'effective_from' => '2026-07-01', 'status' => 'active',
        ]);
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();

        // Partial collection through the shared Finance rows.
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 400,
        ])->assertSessionHasNoErrors();

        $totals = ['assigned' => 1200.5, 'net_collected' => 400.0, 'outstanding' => 800.5, 'assignments' => 1, 'outstanding_assignments' => 1];
        $this->get(route('transport-reports.index', ['report' => 'fee_summary']))
            ->assertViewHas('totals', $totals)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && (float) $rows[0]->ledger['outstanding'] === 800.5);
        $this->get(route('transport-reports.index', ['report' => 'fee_outstanding']))
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$fee->id]);

        // Settle the balance — the outstanding report then shows nothing.
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 800.50,
        ])->assertSessionHasNoErrors();
        $this->get(route('transport-reports.index', ['report' => 'fee_outstanding']))
            ->assertViewHas('totals', fn ($summary) => $summary['outstanding'] === 0.0 && $summary['outstanding_assignments'] === 0)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 0);
    }

    public function test_reports_are_tenant_scoped(): void
    {
        $a = $this->college('RPTT');
        $b = $this->college('RPTT2');
        $this->vehicle($a, 'KA-11 LO-1');
        $foreign = $this->vehicle($b, 'KA-99 FO-9');
        $this->login($a);

        $this->get(route('transport-reports.index', ['report' => 'vehicle_summary']))
            ->assertViewHas('vehicleReport', fn ($rows) => ! $rows->contains('id', $foreign->id) && $rows->count() === 1);
        $this->get(route('transport-reports.index', ['report' => 'vehicle_summary', 'vehicle_id' => $foreign->id]))
            ->assertViewHas('vehicleReport', fn ($rows) => $rows->count() === 0);
    }
}
