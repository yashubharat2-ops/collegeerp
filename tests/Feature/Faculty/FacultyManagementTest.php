<?php

namespace Tests\Feature\Faculty;

use App\Models\{AuditLog, Department, Faculty};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultyManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_faculty_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('FACM');
        $admin = $this->makeUserWithPermissions($college, ['faculties.view', 'faculties.create']);
        $dept = Department::create(['college_id' => $college->id, 'name' => 'Computer Science', 'code' => 'CS', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('faculties.store'), [
                'employee_code' => 'EMP-001',
                'first_name' => 'Alan',
                'middle_name' => 'M.',
                'last_name' => 'Turing',
                'email' => 'alan.turing@example.test',
                'phone' => '1234567890',
                'designation' => 'Professor',
                'department_id' => $dept->id,
                'employment_type' => 'permanent',
                'status' => 'active',
                'joining_date' => '2026-01-15',
            ], ['Referer' => route('faculties.index')])
            ->assertRedirect(route('faculties.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('faculties', [
            'college_id' => $college->id,
            'employee_code' => 'EMP-001',
            'first_name' => 'Alan',
            'middle_name' => 'M.',
            'last_name' => 'Turing',
            'email' => 'alan.turing@example.test',
            'designation' => 'Professor',
            'department_id' => $dept->id,
            'employment_type' => 'permanent',
            'status' => 'active',
        ]);
    }

    public function test_validation_rejects_missing_required_fields(): void
    {
        $college = $this->makeCollege('FACV');
        $admin = $this->makeUserWithPermissions($college, ['faculties.view', 'faculties.create']);

        $this->asCollege($college, $admin)
            ->post(route('faculties.store'), [
                'employee_code' => '',
                'first_name' => '',
                'last_name' => '',
                'status' => 'archived',
            ], ['Referer' => route('faculties.index')])
            ->assertSessionHasErrors(['employee_code', 'first_name', 'last_name', 'status']);

        $this->assertSame(0, Faculty::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_employee_code_in_same_college_is_blocked(): void
    {
        $college = $this->makeCollege('FACD');
        $admin = $this->makeUserWithPermissions($college, ['faculties.view', 'faculties.create', 'faculties.update']);

        Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'EMP-100',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('faculties.store'), [
                'employee_code' => 'EMP-100',
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'status' => 'active',
            ], ['Referer' => route('faculties.index')])
            ->assertSessionHasErrors('employee_code');

        $faculty = Faculty::withoutGlobalScopes()->where('college_id', $college->id)->where('employee_code', 'EMP-100')->firstOrFail();
        $this->asCollege($college, $admin)
            ->put(route('faculties.update', $faculty), [
                'employee_code' => 'EMP-100',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace (Countess)',
                'status' => 'inactive',
            ], ['Referer' => route('faculties.edit', $faculty)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('inactive', $faculty->fresh()->status);
    }

    public function test_admin_can_update_and_soft_delete_faculty(): void
    {
        $college = $this->makeCollege('FACU');
        $admin = $this->makeUserWithPermissions($college, ['faculties.view', 'faculties.update', 'faculties.delete']);

        $faculty = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'FAC-999',
            'first_name' => 'Claude',
            'last_name' => 'Shannon',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('faculties.update', $faculty), [
                'employee_code' => 'FAC-999',
                'first_name' => 'Claude',
                'middle_name' => 'Elwood',
                'last_name' => 'Shannon',
                'designation' => 'Principal Scientist',
                'employment_type' => 'adjunct',
                'status' => 'active',
            ], ['Referer' => route('faculties.edit', $faculty)])
            ->assertSessionHas('success');

        $faculty->refresh();
        $this->assertSame('Claude Elwood Shannon', $faculty->full_name);
        $this->assertSame('Principal Scientist', $faculty->designation);
        $this->assertSame('adjunct', $faculty->employment_type);

        $this->asCollege($college, $admin)
            ->delete(route('faculties.destroy', $faculty), [], ['Referer' => route('faculties.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('faculties', ['id' => $faculty->id]);
        $this->asCollege($college, $admin)->get(route('faculties.index'))->assertDontSee('Claude Elwood Shannon');
    }

    public function test_audit_logs_record_faculty_actions(): void
    {
        $college = $this->makeCollege('FACA');
        $admin = $this->makeUserWithPermissions($college, ['faculties.view', 'faculties.create', 'faculties.update', 'faculties.delete']);

        $this->asCollege($college, $admin)->post(route('faculties.store'), [
            'employee_code' => 'AUD-EMP',
            'first_name' => 'Audit',
            'last_name' => 'User',
            'status' => 'active',
        ], ['Referer' => route('faculties.index')])->assertSessionHasNoErrors();

        $fac = Faculty::withoutGlobalScopes()->where('employee_code', 'AUD-EMP')->firstOrFail();

        $this->asCollege($college, $admin)->put(route('faculties.update', $fac), [
            'employee_code' => 'AUD-EMP',
            'first_name' => 'Audit',
            'last_name' => 'Modified',
            'status' => 'active',
        ], ['Referer' => route('faculties.edit', $fac)])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)->delete(route('faculties.destroy', $fac), [], ['Referer' => route('faculties.index')]);

        $base = [
            'college_id' => $college->id,
            'user_id' => $admin->id,
            'subject_type' => Faculty::class,
            'subject_id' => $fac->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'faculty.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'faculty.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'faculty.deleted']);
    }
}
