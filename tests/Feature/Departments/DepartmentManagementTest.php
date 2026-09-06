<?php

namespace Tests\Feature\Departments;

use App\Models\{AuditLog, Department};
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_department_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('MGMT');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.create']);

        $this->asCollege($college, $admin)
            ->post(route('departments.store'), ['name' => 'Mathematics', 'code' => 'MATH', 'description' => 'Pure and applied maths', 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('departments', ['college_id' => $college->id, 'name' => 'Mathematics', 'code' => 'MATH', 'status' => 'active']);
    }

    public function test_validation_rejects_missing_fields_and_bad_status(): void
    {
        $college = $this->makeCollege('VAL');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.create']);

        $this->asCollege($college, $admin)
            ->post(route('departments.store'), ['name' => '', 'code' => '', 'status' => 'archived'], ['Referer' => route('departments.index')])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $admin)
            ->post(route('departments.store'), ['name' => str_repeat('x', 256), 'code' => str_repeat('c', 51), 'description' => str_repeat('d', 2001), 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasErrors(['name', 'code', 'description']);

        $this->assertSame(0, Department::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_code_within_same_college_is_blocked(): void
    {
        $college = $this->makeCollege('DUP');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.create', 'departments.update']);
        Department::create(['college_id' => $college->id, 'name' => 'Physics', 'code' => 'PHY', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('departments.store'), ['name' => 'Applied Physics', 'code' => 'PHY', 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasErrors('code');

        // Duplicate name in the same college is also rejected.
        $this->asCollege($college, $admin)
            ->post(route('departments.store'), ['name' => 'Physics', 'code' => 'PHY2', 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasErrors('name');

        // Editing the same row keeps its own code (ignore rule).
        $department = Department::withoutGlobalScopes()->where('college_id', $college->id)->where('code', 'PHY')->firstOrFail();
        $this->asCollege($college, $admin)
            ->put(route('departments.update', $department), ['name' => 'Physics', 'code' => 'PHY', 'status' => 'inactive'], ['Referer' => route('departments.edit', $department)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertSame('inactive', $department->fresh()->status);
    }

    public function test_admin_can_update_and_delete_department(): void
    {
        $college = $this->makeCollege('UPD');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.create', 'departments.update', 'departments.delete']);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Chemistry', 'code' => 'CH', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->put(route('departments.update', $department), ['name' => 'Applied Chemistry', 'code' => 'CH', 'description' => 'updated', 'status' => 'active'], ['Referer' => route('departments.edit', $department)])
            ->assertSessionHas('success');
        $department->refresh();
        $this->assertSame('Applied Chemistry', $department->name);
        $this->assertSame('updated', $department->description);

        $this->asCollege($college, $admin)
            ->delete(route('departments.destroy', $department), [], ['Referer' => route('departments.index')])
            ->assertSessionHas('success');
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
        $this->asCollege($college, $admin)->get(route('departments.index'))->assertDontSee('Applied Chemistry');
    }

    public function test_activate_and_deactivate_actions_update_status(): void
    {
        $college = $this->makeCollege('STS');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.update']);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Botany', 'code' => 'BOT', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->patch(route('departments.status', $department), ['status' => 'inactive'], ['Referer' => route('departments.index')])
            ->assertSessionHas('success');
        $this->assertSame('inactive', $department->fresh()->status);

        $this->asCollege($college, $admin)
            ->patch(route('departments.status', $department), ['status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHas('success');
        $this->assertSame('active', $department->fresh()->status);

        $this->asCollege($college, $admin)
            ->patch(route('departments.status', $department), ['status' => 'retired'], ['Referer' => route('departments.index')])
            ->assertSessionHasErrors('status');
    }

    public function test_index_supports_search_status_filter_and_server_side_pagination(): void
    {
        $college = $this->makeCollege('IDX');
        $admin = $this->makeUserWithPermissions($college, ['departments.view']);
        foreach (range(1, 16) as $i) {
            Department::create(['college_id' => $college->id, 'name' => sprintf('Dept %02d', $i), 'code' => sprintf('D%02d', $i), 'status' => $i === 16 ? 'inactive' : 'active']);
        }

        $this->asCollege($college, $admin)->get(route('departments.index'))
            ->assertSee('Dept 01')->assertSee('Dept 15')->assertDontSee('Dept 16')
            ->assertSee('Showing 1–15 of 16 departments.');

        $this->asCollege($college, $admin)->get(route('departments.index', ['page' => 2]))
            ->assertSee('Dept 16')->assertDontSee('Dept 01');

        $this->asCollege($college, $admin)->get(route('departments.index', ['search' => 'Dept 07']))
            ->assertSee('Dept 07')->assertDontSee('Dept 01');

        $this->asCollege($college, $admin)->get(route('departments.index', ['search' => 'D07']))
            ->assertSee('Dept 07');

        $this->asCollege($college, $admin)->get(route('departments.index', ['status' => 'inactive']))
            ->assertSee('Dept 16')->assertDontSee('Dept 01');
    }

    public function test_audit_logs_record_create_update_status_and_delete(): void
    {
        $college = $this->makeCollege('AUD');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.create', 'departments.update', 'departments.delete']);

        $this->asCollege($college, $admin)->post(route('departments.store'), ['name' => 'Zoology', 'code' => 'ZOO', 'status' => 'active'], ['Referer' => route('departments.index')])->assertSessionHasNoErrors();
        $department = Department::withoutGlobalScopes()->firstWhere('code', 'ZOO');

        $this->asCollege($college, $admin)->put(route('departments.update', $department), ['name' => 'Zoography', 'code' => 'ZOO', 'status' => 'active'], ['Referer' => route('departments.edit', $department)]);
        $this->asCollege($college, $admin)->patch(route('departments.status', $department), ['status' => 'inactive'], ['Referer' => route('departments.index')]);
        $this->asCollege($college, $admin)->delete(route('departments.destroy', $department), [], ['Referer' => route('departments.index')]);

        $base = ['college_id' => $college->id, 'user_id' => $admin->id, 'subject_type' => Department::class, 'subject_id' => $department->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'department.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'department.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'department.status_changed']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'department.deleted']);

        $updated = AuditLog::where($base + ['action' => 'department.updated'])->firstOrFail();
        $this->assertSame('Zoology', $updated->old_values['name']);
        $this->assertSame('Zoography', $updated->new_values['name']);

        $status = AuditLog::where($base + ['action' => 'department.status_changed'])->firstOrFail();
        $this->assertSame(['status' => 'active'], $status->old_values);
        $this->assertSame(['status' => 'inactive'], $status->new_values);
    }
}
