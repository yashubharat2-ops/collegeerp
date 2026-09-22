<?php

namespace Tests\Feature\Transport;

use App\Models\{AcademicYear, College, Permission, Role, Student, StudentEnrollment, StudentTransportAssignment, StudentTransportFeeAssignment, TransportFeeStructure, TransportRoute, TransportStop, User, Vehicle};
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transport Phase 2 — Student Transport Assignment: existing enrollments onto
 * existing routes/stops (no duplicate masters), stop-belongs-to-route, one
 * ACTIVE assignment per enrollment + academic year, date validation, preserved
 * history and full server-side tenancy/actor control.
 */
class StudentTransportAssignmentTest extends TestCase
{
    private const PERMISSIONS = [
        'student_transport_assignments.view', 'student_transport_assignments.create',
        'student_transport_assignments.update', 'student_transport_assignments.delete',
    ];

    private function college(string $code): College
    {
        return College::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'status' => 'active']);
    }

    private function user(College $college, ?array $permissions = null): User
    {
        $user = User::create(['name' => 'Assign Staff', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $role = Role::create(['college_id' => $college->id, 'name' => 'Assignments', 'slug' => (string) Str::uuid(), 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions ?? self::PERMISSIONS)->pluck('id'));
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

    private function enrollment(College $college, AcademicYear $year, string $suffix = 'A'): StudentEnrollment
    {
        $student = $this->fixture(Student::class, $college, [
            'student_number' => 'STU-'.$suffix,
            'first_name' => 'Test',
            'last_name' => 'Student '.$suffix,
            'status' => 'active',
        ]);
        return $this->fixture(StudentEnrollment::class, $college, [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-'.$suffix,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function year(College $college, string $suffix = ''): AcademicYear
    {
        return $this->fixture(AcademicYear::class, $college, [
            'name' => 'Year '.($suffix ?: Str::upper(Str::random(4))),
            'code' => Str::upper(Str::random(6)),
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
    }

    private function route(College $college, string $code = 'NORTH'): TransportRoute
    {
        return $this->fixture(TransportRoute::class, $college, ['name' => 'Route '.$code, 'code' => $code, 'status' => 'active']);
    }

    private function stop(College $college, TransportRoute $route, int $sequence = 1): TransportStop
    {
        return $this->fixture(TransportStop::class, $college, [
            'route_id' => $route->id,
            'name' => 'Stop '.$sequence.' '.$route->code,
            'code' => Str::upper(Str::random(6)),
            'sequence' => $sequence,
            'status' => 'active',
        ]);
    }

    private function payload(StudentEnrollment $enrollment, AcademicYear $year, TransportRoute $route, TransportStop $stop, array $extra = []): array
    {
        return array_merge([
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'end_date' => '2027-04-30',
            'status' => 'active',
        ], $extra);
    }

    public function test_create_stamps_server_fields_and_enforces_stop_route_and_dates(): void
    {
        $a = $this->college('ASSIGN');
        $b = $this->college('ASSIGN2');
        $year = $this->year($a);
        $enrollment = $this->enrollment($a, $year);
        $routeA = $this->route($a, 'RA');
        $routeB = $this->route($a, 'RB');
        $stopB = $this->stop($a, $routeB);
        $user = $this->login($a);

        $this->get(route('transport-assignments.create'))->assertOk();
        // The stop must belong to the selected route (service re-check under lock).
        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $routeA, $stopB))->assertSessionHasErrors('transport_stop_id');
        // Coherent dates required (request-level validation).
        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $routeA, $stopB, ['end_date' => '2026-08-01']))->assertSessionHasErrors('end_date');

        $stopA = $this->stop($a, $routeA);
        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $routeA, $stopA, [
            'college_id' => $b->id, 'created_by' => 9999, 'updated_by' => 9999,
        ]))->assertSessionHasNoErrors()->assertRedirect(route('transport-assignments.index'));

        $assignment = StudentTransportAssignment::withoutGlobalScopes()->firstOrFail();
        $this->assertEquals($a->id, $assignment->college_id);
        $this->assertEquals($user->id, $assignment->created_by);
        $this->assertEquals($stopA->id, $assignment->transport_stop_id);
        $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'action' => 'student_transport_assignments.created', 'subject_id' => $assignment->id]);
        $this->get(route('transport-assignments.index'))->assertOk()->assertSee('STU-A');
    }

    public function test_only_one_active_assignment_per_enrollment_and_year_with_history_preserved(): void
    {
        $a = $this->college('DUPAS');
        $year = $this->year($a);
        $enrollment = $this->enrollment($a, $year);
        $route = $this->route($a);
        $stop = $this->stop($a, $route);
        $this->login($a);

        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $route, $stop))->assertSessionHasNoErrors();
        // A second ACTIVE row for the same enrollment + year is refused…
        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $route, $stop, ['start_date' => '2026-10-01']))
            ->assertSessionHasErrors('student_enrollment_id');

        $assignment = StudentTransportAssignment::withoutGlobalScopes()->firstOrFail();
        // …but history never blocks: completing the active row allows a new one.
        $this->put(route('transport-assignments.update', $assignment->id), $this->payload($enrollment, $year, $route, $stop, ['status' => 'completed']))->assertSessionHasNoErrors();
        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $route, $stop, ['start_date' => '2026-10-01']))->assertSessionHasNoErrors();
        $this->assertSame(2, StudentTransportAssignment::withoutGlobalScopes()->count());

        // Re-activating a completed row while another is active is refused too.
        $this->put(route('transport-assignments.update', $assignment->id), $this->payload($enrollment, $year, $route, $stop, ['status' => 'active']))->assertSessionHasErrors('status');
    }

    public function test_database_partial_unique_index_blocks_racing_active_duplicates(): void
    {
        $a = $this->college('DUPDB');
        $year = $this->year($a);
        $enrollment = $this->enrollment($a, $year);
        $route = $this->route($a);
        $stop = $this->stop($a, $route);
        $this->fixture(StudentTransportAssignment::class, $a, [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->fixture(StudentTransportAssignment::class, $a, [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-02',
            'status' => 'active',
        ]);
    }

    public function test_update_keeps_enrollment_and_year_immutable(): void
    {
        $a = $this->college('IMMUT');
        $year = $this->year($a);
        $year2 = $this->year($a, 'B');
        $enrollment = $this->enrollment($a, $year);
        $enrollment2 = $this->enrollment($a, $year, 'B');
        $route = $this->route($a);
        $stop = $this->stop($a, $route);
        $stop2 = $this->stop($a, $route, 2);
        $user = $this->login($a);

        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $route, $stop))->assertSessionHasNoErrors();
        $assignment = StudentTransportAssignment::withoutGlobalScopes()->firstOrFail();

        $this->put(route('transport-assignments.update', $assignment->id), $this->payload($enrollment2, $year2, $route, $stop2, [
            'remarks' => 'moved house',
            'student_enrollment_id' => $enrollment2->id,
            'academic_year_id' => $year2->id,
        ]))->assertSessionHasNoErrors();

        $assignment->refresh();
        // Re-pointing is silently impossible: enrollment/year are not input.
        $this->assertEquals($enrollment->id, $assignment->student_enrollment_id);
        $this->assertEquals($year->id, $assignment->academic_year_id);
        $this->assertEquals($stop2->id, $assignment->transport_stop_id);
        $this->assertSame('moved house', $assignment->remarks);
        $this->assertEquals($user->id, $assignment->updated_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transport_assignments.updated', 'subject_id' => $assignment->id]);
    }

    public function test_delete_preserves_history_and_is_blocked_by_payable_fee_assignments(): void
    {
        $a = $this->college('DELAS');
        $year = $this->year($a);
        $enrollment = $this->enrollment($a, $year);
        $route = $this->route($a);
        $stop = $this->stop($a, $route);
        $user = $this->login($a);
        $this->post(route('transport-assignments.store'), $this->payload($enrollment, $year, $route, $stop))->assertSessionHasNoErrors();
        $assignment = StudentTransportAssignment::withoutGlobalScopes()->firstOrFail();

        // A payable transport fee assignment blocks deletion.
        $structure = $this->fixture(TransportFeeStructure::class, $a, [
            'academic_year_id' => $year->id, 'name' => 'Fee', 'code' => Str::upper(Str::random(6)),
            'amount' => 500, 'effective_from' => '2026-09-01', 'status' => 'active',
        ]);
        $fee = $this->fixture(StudentTransportFeeAssignment::class, $a, [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id,
            'amount' => 500,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ]);
        $this->delete(route('transport-assignments.destroy', $assignment->id))->assertSessionHasErrors('student_enrollment_id');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_transport_assignments.deleted']);

        // Cancelling the fee unblocks; deletion stays a soft delete with audit.
        $fee->update(['status' => 'cancelled']);
        $this->delete(route('transport-assignments.destroy', $assignment->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('student_transport_assignments', ['id' => $assignment->id, 'updated_by' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transport_assignments.deleted', 'subject_id' => $assignment->id]);
    }

    public function test_every_endpoint_is_tenant_scoped(): void
    {
        $a = $this->college('TENAS');
        $b = $this->college('TENAS2');
        $yearB = $this->year($b);
        $routeB = $this->route($b);
        $stopB = $this->stop($b, $routeB);
        $enrollmentB = $this->enrollment($b, $yearB);
        $foreign = $this->fixture(StudentTransportAssignment::class, $b, [
            'student_enrollment_id' => $enrollmentB->id,
            'academic_year_id' => $yearB->id,
            'transport_route_id' => $routeB->id,
            'transport_stop_id' => $stopB->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->login($a);
        $this->get(route('transport-assignments.edit', $foreign->id))->assertNotFound();
        $this->put(route('transport-assignments.update', $foreign->id), [])->assertNotFound();
        $this->delete(route('transport-assignments.destroy', $foreign->id))->assertNotFound();
        $this->get(route('transport-assignments.index'))->assertViewHas('assignments', fn ($rows) => ! $rows->contains('id', $foreign->id));

        // Foreign masters never validate as inputs against the active college.
        $this->post(route('transport-assignments.store'), $this->payload($enrollmentB, $yearB, $routeB, $stopB))
            ->assertSessionHasErrors(['student_enrollment_id', 'academic_year_id', 'transport_route_id', 'transport_stop_id']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_transport_assignments.deleted']);
    }

    public function test_database_rejects_cross_college_stop_parent(): void
    {
        $a = $this->college('FKAS');
        $b = $this->college('FKAS2');
        $year = $this->year($a);
        $enrollment = $this->enrollment($a, $year);
        $route = $this->route($a);
        $stop = $this->stop($b, $this->route($b));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->fixture(StudentTransportAssignment::class, $a, [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);
    }

    public function test_rbac_gates_every_action(): void
    {
        $a = $this->college('RBACAS');
        $year = $this->year($a);
        $enrollment = $this->enrollment($a, $year);
        $route = $this->route($a);
        $stop = $this->stop($a, $route);
        $assignment = $this->fixture(StudentTransportAssignment::class, $a, [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->login($a, []);
        $this->get(route('transport-assignments.index'))->assertForbidden();
        $this->get(route('transport-assignments.create'))->assertForbidden();
        $this->post(route('transport-assignments.store'), [])->assertForbidden();
        $this->get(route('transport-assignments.edit', $assignment->id))->assertForbidden();
        $this->put(route('transport-assignments.update', $assignment->id), [])->assertForbidden();
        $this->delete(route('transport-assignments.destroy', $assignment->id))->assertForbidden();

        $this->login($a, ['student_transport_assignments.view']);
        $this->get(route('transport-assignments.index'))->assertOk();
        $this->post(route('transport-assignments.store'), [])->assertForbidden();
    }
}
