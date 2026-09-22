<?php

namespace Tests\Feature\Transport;

use App\Models\{AuditLog, College, Faculty, Permission, Role, TransportDriver, TransportRoute, TransportStop, User, Vehicle};
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TransportPermissionSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransportManagementTest extends TestCase
{
    private const PERMISSIONS = [
        'transport_dashboard.view',
        'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete',
        'transport_drivers.view', 'transport_drivers.create', 'transport_drivers.update', 'transport_drivers.delete',
        'transport_routes.view', 'transport_routes.create', 'transport_routes.update', 'transport_routes.delete',
    ];

    private function college(string $code): College
    {
        return College::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'status' => 'active']);
    }

    private function user(College $college, ?array $permissions = null): User
    {
        $user = User::create(['name' => 'Transport Staff', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $role = Role::create(['college_id' => $college->id, 'name' => 'Transport', 'slug' => (string) Str::uuid(), 'is_active' => true]);
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

    private function vehiclePayload(array $extra = []): array
    {
        return array_replace(['registration_number' => ' ka-01 ab-1234 ', 'seating_capacity' => 40, 'status' => 'active'], $extra);
    }

    private function routePayload(array $extra = []): array
    {
        return array_replace(['name' => 'North Route', 'code' => ' north ', 'status' => 'active'], $extra);
    }

    private function stopPayload(array $extra = []): array
    {
        return array_replace(['name' => 'Main Gate', 'code' => ' gate ', 'sequence' => 1, 'pickup_time' => '08:15', 'drop_time' => '17:30', 'status' => 'active'], $extra);
    }

    private function staff(College $college): Faculty
    {
        return Faculty::create(['college_id' => $college->id, 'employee_code' => Str::upper(Str::random(8)), 'first_name' => 'Existing', 'last_name' => 'Staff', 'status' => 'active']);
    }

    private function driverPayload(Faculty $staff, array $extra = []): array
    {
        return array_replace(['faculty_id' => $staff->id, 'license_number' => ' dl-001 ', 'license_type' => 'Heavy passenger', 'license_expiry' => '2028-12-31', 'joining_date' => '2026-01-01', 'status' => 'active'], $extra);
    }

    public function test_vehicle_crud_normalization_server_fields_and_audits(): void
    {
        $a = $this->college('VEH');
        $b = $this->college('OTHER');
        $user = $this->login($a);
        $this->get(route('vehicles.create'))->assertOk();
        $this->post(route('vehicles.store'), $this->vehiclePayload(['college_id' => $b->id, 'created_by' => 9999, 'updated_by' => 9999]))->assertSessionHasNoErrors()->assertRedirect(route('vehicles.index'));
        $vehicle = Vehicle::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('KA-01 AB-1234', $vehicle->registration_number);
        $this->assertEquals($a->id, $vehicle->college_id);
        $this->assertEquals($user->id, $vehicle->created_by);
        $this->get(route('vehicles.index'))->assertOk()->assertSee('KA-01 AB-1234');
        $this->get(route('vehicles.edit', $vehicle->id))->assertOk();
        $this->put(route('vehicles.update', $vehicle->id), $this->vehiclePayload(['status' => 'maintenance']))->assertSessionHasNoErrors();
        $this->delete(route('vehicles.destroy', $vehicle->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id, 'updated_by' => $user->id]);
        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'user_id' => $user->id, 'action' => 'vehicles.'.$event, 'subject_id' => $vehicle->id]);
        }
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'vehicles.updated')->firstOrFail();
        $this->assertSame('active', $audit->old_values['status']);
        $this->assertSame('maintenance', $audit->new_values['status']);
    }

    public function test_vehicle_uniqueness_is_per_college_and_includes_archived_records(): void
    {
        $a = $this->college('UNIQ');
        $b = $this->college('UNIQ2');
        $this->fixture(Vehicle::class, $b, $this->vehiclePayload());
        $this->login($a);
        $this->post(route('vehicles.store'), $this->vehiclePayload())->assertSessionHasNoErrors();
        $this->post(route('vehicles.store'), $this->vehiclePayload(['registration_number' => 'KA-01 AB-1234']))->assertSessionHasErrors('registration_number');
        $id = Vehicle::withoutGlobalScopes()->where('college_id', $a->id)->value('id');
        $this->delete(route('vehicles.destroy', $id));
        $this->post(route('vehicles.store'), $this->vehiclePayload())->assertSessionHasErrors('registration_number');
    }

    public function test_invalid_vehicle_capacity_dates_and_status_do_not_write_or_audit(): void
    {
        $this->login($this->college('INVALID'));
        foreach ([['seating_capacity' => 0], ['seating_capacity' => -1], ['seating_capacity' => 1.5], ['insurance_expiry' => '2026-02-30'], ['purchase_date' => 'yesterday'], ['status' => 'unknown']] as $bad) {
            $this->post(route('vehicles.store'), $this->vehiclePayload($bad))->assertSessionHasErrors(array_key_first($bad));
        }
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vehicles.created']);
    }

    public function test_driver_reuses_staff_and_validates_tenant_license_and_active_duplicates(): void
    {
        $a = $this->college('DRIVER');
        $b = $this->college('DRIVER2');
        $staff = $this->staff($a);
        $foreign = $this->staff($b);
        $this->login($a);
        $this->get(route('transport-drivers.create'))->assertOk()->assertSee($staff->employee_code)->assertDontSee($foreign->employee_code);
        $this->post(route('transport-drivers.store'), $this->driverPayload($foreign))->assertSessionHasErrors('faculty_id');
        $this->post(route('transport-drivers.store'), $this->driverPayload($staff, ['license_expiry' => '2026-02-30']))->assertSessionHasErrors('license_expiry');
        $this->post(route('transport-drivers.store'), $this->driverPayload($staff))->assertSessionHasNoErrors();
        $driver = TransportDriver::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('DL-001', $driver->license_number);
        $this->assertEquals($staff->id, $driver->faculty_id);
        $this->post(route('transport-drivers.store'), $this->driverPayload($staff, ['license_number' => 'DL-002']))->assertSessionHasErrors('faculty_id');
        $this->post(route('transport-drivers.store'), $this->driverPayload($this->staff($a)))->assertSessionHasErrors('license_number');
        $this->put(route('transport-drivers.update', $driver->id), $this->driverPayload($foreign))->assertSessionHasErrors('faculty_id');
        $this->put(route('transport-drivers.update', $driver->id), $this->driverPayload($staff, ['status' => 'inactive']))->assertSessionHasNoErrors();
        $this->post(route('transport-drivers.store'), $this->driverPayload($staff, ['license_number' => 'DL-002']))->assertSessionHasNoErrors();
        $this->put(route('transport-drivers.update', $driver->id), $this->driverPayload($staff))->assertSessionHasErrors('faculty_id');
        $this->get(route('transport-drivers.index'))->assertOk()->assertSee('Existing Staff');
        $this->get(route('transport-drivers.edit', $driver->id))->assertOk();
        $this->delete(route('transport-drivers.destroy', $driver->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('transport_drivers', ['id' => $driver->id]);
        $this->assertDatabaseHas('faculties', ['id' => $staff->id, 'deleted_at' => null]);
        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['action' => 'transport_drivers.'.$event, 'subject_id' => $driver->id]);
        }
    }

    public function test_routes_and_nested_stops_crud_sequence_and_history(): void
    {
        $a = $this->college('ROUTES');
        $user = $this->login($a);
        $this->get(route('transport-routes.create'))->assertOk();
        $this->post(route('transport-routes.store'), $this->routePayload())->assertSessionHasNoErrors();
        $route = TransportRoute::withoutGlobalScopes()->firstOrFail();
        $this->post(route('transport-routes.store'), $this->routePayload())->assertSessionHasErrors('code');
        $this->get(route('transport-routes.index'))->assertOk();
        $this->get(route('transport-routes.edit', $route->id))->assertOk();
        $this->put(route('transport-routes.update', $route->id), $this->routePayload(['name' => 'North Updated']))->assertSessionHasNoErrors();
        $params = ['transport_route' => $route->id];
        $this->get(route('transport-stops.create', $params))->assertOk();
        $this->post(route('transport-stops.store', $params), $this->stopPayload(['sequence' => 20, 'route_id' => 9999, 'college_id' => 9999, 'created_by' => 9999]))->assertSessionHasNoErrors();
        $stop = TransportStop::withoutGlobalScopes()->firstOrFail();
        $this->assertEquals($a->id, $stop->college_id);
        $this->assertEquals($route->id, $stop->route_id);
        $this->assertEquals($user->id, $stop->created_by);
        $this->post(route('transport-stops.store', $params), $this->stopPayload(['code' => 'SECOND', 'name' => 'Earlier Stop', 'sequence' => 2]))->assertSessionHasNoErrors();
        $this->get(route('transport-stops.index', $params))->assertOk()->assertSeeInOrder(['Earlier Stop', 'Main Gate'])
            ->assertViewHas('records', fn ($records) => $records->pluck('sequence')->all() === [2, 20]);
        $this->post(route('transport-stops.store', $params), $this->stopPayload())->assertSessionHasErrors('code');
        $this->post(route('transport-stops.store', $params), $this->stopPayload(['code' => 'THIRD', 'sequence' => 2]))->assertSessionHasErrors('sequence');
        $this->post(route('transport-stops.store', $params), $this->stopPayload(['code' => 'THIRD', 'sequence' => 0, 'pickup_time' => '25:99']))->assertSessionHasErrors(['sequence', 'pickup_time']);
        $this->get(route('transport-stops.edit', $params + ['record' => $stop->id]))->assertOk();
        $this->put(route('transport-stops.update', $params + ['record' => $stop->id]), $this->stopPayload(['sequence' => 3]))->assertSessionHasNoErrors();
        $this->delete(route('transport-routes.destroy', $route->id))->assertSessionHasErrors('route');
        foreach (TransportStop::withoutGlobalScopes()->get() as $row) {
            $this->delete(route('transport-stops.destroy', $params + ['record' => $row->id]))->assertSessionHasNoErrors();
            $this->assertSoftDeleted('transport_stops', ['id' => $row->id]);
        }
        $this->delete(route('transport-routes.destroy', $route->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('transport_routes', ['id' => $route->id]);
        foreach (['transport_routes', 'transport_stops'] as $module) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'action' => $module.'.'.$event]);
            }
        }
    }

    public function test_all_resource_ids_and_stop_parents_are_tenant_scoped(): void
    {
        $a = $this->college('LOCAL');
        $b = $this->college('FOREIGN');
        $foreignVehicle = $this->fixture(Vehicle::class, $b, $this->vehiclePayload());
        $foreignDriver = $this->fixture(TransportDriver::class, $b, $this->driverPayload($this->staff($b)));
        $foreignRoute = $this->fixture(TransportRoute::class, $b, $this->routePayload());
        $localRoute = $this->fixture(TransportRoute::class, $a, $this->routePayload());
        $otherRoute = $this->fixture(TransportRoute::class, $a, $this->routePayload(['code' => 'OTHER']));
        $stop = $this->fixture(TransportStop::class, $b, $this->stopPayload(['route_id' => $foreignRoute->id]));
        $localStop = $this->fixture(TransportStop::class, $a, $this->stopPayload(['route_id' => $localRoute->id]));
        $this->login($a);
        foreach (['vehicles' => $foreignVehicle, 'transport-drivers' => $foreignDriver, 'transport-routes' => $foreignRoute] as $name => $record) {
            $this->get(route($name.'.edit', $record->id))->assertNotFound();
            $this->put(route($name.'.update', $record->id), [])->assertNotFound();
            $this->delete(route($name.'.destroy', $record->id))->assertNotFound();
            $this->get(route($name.'.index'))->assertViewHas('records', fn ($records) => ! $records->contains('id', $record->id));
        }
        $foreignParams = ['transport_route' => $foreignRoute->id];
        $this->get(route('transport-stops.index', $foreignParams))->assertNotFound();
        $this->post(route('transport-stops.store', $foreignParams), $this->stopPayload())->assertNotFound();
        foreach ([['transport_route' => $localRoute->id, 'record' => $stop->id], ['transport_route' => $otherRoute->id, 'record' => $localStop->id]] as $params) {
            $this->get(route('transport-stops.edit', $params))->assertNotFound();
            $this->put(route('transport-stops.update', $params), $this->stopPayload())->assertNotFound();
            $this->delete(route('transport-stops.destroy', $params))->assertNotFound();
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'transport_stops.deleted']);
    }

    public function test_dashboard_uses_only_live_active_college_records(): void
    {
        $a = $this->college('DASH');
        $b = $this->college('DASH2');
        $this->fixture(Vehicle::class, $a, $this->vehiclePayload());
        $this->fixture(Vehicle::class, $a, $this->vehiclePayload(['registration_number' => 'BUS2', 'status' => 'maintenance']));
        $this->fixture(Vehicle::class, $a, $this->vehiclePayload(['registration_number' => 'BUS3']))->delete();
        $this->fixture(Vehicle::class, $b, $this->vehiclePayload());
        $this->fixture(TransportDriver::class, $a, $this->driverPayload($this->staff($a)));
        $route = $this->fixture(TransportRoute::class, $a, $this->routePayload());
        $this->fixture(TransportStop::class, $a, $this->stopPayload(['route_id' => $route->id]));
        $this->login($a, ['transport_dashboard.view']);
        $this->get(route('transport.dashboard'))->assertOk()->assertViewHas('stats', [
            'Total Vehicles' => 2, 'Active Vehicles' => 1, 'Total Drivers' => 1, 'Active Drivers' => 1, 'Total Routes' => 1, 'Total Stops' => 1,
        ]);
    }

    public function test_rbac_denies_every_endpoint_without_permission(): void
    {
        $a = $this->college('RBAC');
        $route = $this->fixture(TransportRoute::class, $a, $this->routePayload());
        $resources = [
            'vehicles' => [$this->fixture(Vehicle::class, $a, $this->vehiclePayload()), []],
            'transport-drivers' => [$this->fixture(TransportDriver::class, $a, $this->driverPayload($this->staff($a))), []],
            'transport-routes' => [$route, []],
            'transport-stops' => [$this->fixture(TransportStop::class, $a, $this->stopPayload(['route_id' => $route->id])), ['transport_route' => $route->id]],
        ];
        $this->get(route('transport.dashboard'))->assertRedirect(route('login'));
        $this->login($a, []);
        $this->get(route('transport.dashboard'))->assertForbidden();
        foreach ($resources as $name => [$record, $params]) {
            $this->get(route($name.'.index', $params))->assertForbidden();
            $this->get(route($name.'.create', $params))->assertForbidden();
            $this->post(route($name.'.store', $params), [])->assertForbidden();
            $this->get(route($name.'.edit', $params + ['record' => $record->id]))->assertForbidden();
            $this->put(route($name.'.update', $params + ['record' => $record->id]), [])->assertForbidden();
            $this->delete(route($name.'.destroy', $params + ['record' => $record->id]))->assertForbidden();
        }
    }

    public function test_navigation_is_individually_permission_gated_and_view_is_not_write(): void
    {
        $a = $this->college('NAV');
        $items = ['transport_dashboard.view' => 'transport.dashboard', 'vehicles.view' => 'vehicles.index', 'transport_drivers.view' => 'transport-drivers.index', 'transport_routes.view' => 'transport-routes.index'];
        foreach ($items as $permission => $route) {
            $this->login($a, [$permission]);
            $response = $this->get(route($route))->assertOk()->assertSee('Transport Management');
            foreach ($items as $other => $target) {
                if ($other !== $permission) {
                    $response->assertDontSee('href="'.route($target).'"', false);
                }
            }
            foreach (['vehicles', 'transport-drivers', 'transport-routes'] as $resource) {
                $this->post(route($resource.'.store'), [])->assertForbidden();
            }
        }
    }

    public function test_super_admin_requires_authorized_active_college_and_remains_scoped(): void
    {
        $a = $this->college('SUPER1');
        $b = $this->college('SUPER2');
        $user = $this->user($a, []);
        $super = Role::where('slug', 'super-admin')->whereNull('college_id')->firstOrFail();
        $user->roles()->attach($super->id, ['college_id' => null]);
        $foreign = $this->fixture(Vehicle::class, $b, $this->vehiclePayload());
        $this->actingAs($user)->withSession(['active_college_id' => $a->id]);
        $this->get(route('vehicles.edit', $foreign->id))->assertNotFound();
        $this->withSession(['active_college_id' => $b->id])->get(route('vehicles.index'))->assertForbidden();
        $this->withSession(['active_college_id' => $a->id]);
        $this->post(route('college-context.switch'), ['college_id' => $b->id])->assertRedirect();
        $this->get(route('vehicles.edit', $foreign->id))->assertOk();
        $this->post(route('vehicles.store'), $this->vehiclePayload(['registration_number' => 'SUPER-BUS', 'college_id' => $a->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicles', ['registration_number' => 'SUPER-BUS', 'college_id' => $b->id]);
        $user->colleges()->detach();
        app(TenantContext::class)->clear();
        $this->withSession(['active_college_id' => null, 'active_college_grant' => null])->get(route('transport.dashboard'))->assertForbidden();
    }

    public function test_model_scopes_fail_closed_and_role_grants_do_not_cross_colleges(): void
    {
        $a = $this->college('SCOPE1');
        $b = $this->college('SCOPE2');
        $this->fixture(Vehicle::class, $a, $this->vehiclePayload());
        app(TenantContext::class)->clear();
        $this->assertSame(0, Vehicle::query()->count());
        $user = $this->user($a);
        $user->colleges()->attach($b->id, ['is_default' => false]);
        $this->actingAs($user)->withSession(['active_college_id' => $b->id]);
        $this->get(route('vehicles.index'))->assertForbidden();
        $this->post(route('vehicles.store'), $this->vehiclePayload())->assertForbidden();
    }

    public function test_service_rejects_foreign_models_even_outside_http_resolution(): void
    {
        $a = $this->college('SERVICE1');
        $b = $this->college('SERVICE2');
        $vehicle = $this->fixture(Vehicle::class, $b, $this->vehiclePayload());
        $user = $this->user($a);
        app(TenantContext::class)->set($a);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Domain\Transport\Services\TransportMasterService::class)->save($vehicle, $this->vehiclePayload(), $user);
    }

    public function test_database_rejects_cross_college_stop_parent(): void
    {
        $a = $this->college('FK1');
        $b = $this->college('FK2');
        $route = $this->fixture(TransportRoute::class, $a, $this->routePayload());
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->fixture(TransportStop::class, $b, $this->stopPayload(['route_id' => $route->id]));
    }

    public function test_database_rejects_duplicate_live_driver_for_staff(): void
    {
        $a = $this->college('DUPSTAFF');
        $staff = $this->staff($a);
        $this->fixture(TransportDriver::class, $a, $this->driverPayload($staff));
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->fixture(TransportDriver::class, $a, $this->driverPayload($staff, ['license_number' => 'OTHER']));
    }

    public function test_permission_seeding_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TransportPermissionSeeder::class);
        $this->seed(TransportPermissionSeeder::class);
        $this->assertSame(13, Permission::whereIn('slug', self::PERMISSIONS)->count());
        foreach (self::PERMISSIONS as $slug) {
            $this->assertSame(1, Permission::where('slug', $slug)->count());
        }
    }
}
