<?php

namespace Tests\Feature\HR;

use App\Models\{Department, Designation, Faculty};
use Tests\TestCase;

class HRManagementTest extends TestCase
{
    use HRTestHelpers;

    public function test_designation_and_employee_crud_reuse_tenant_department_records(): void
    {
        $college = $this->makeCollege('HRCRUD');
        $admin = $this->makeUserWithPermissions($college, [
            'faculties.view', 'faculties.create', 'faculties.update', 'faculties.delete',
            'designations.view', 'designations.create', 'designations.update', 'designations.delete',
            'departments.view', 'departments.create', 'departments.update', 'departments.delete',
        ]);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Humanities', 'code' => 'HUM', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('designations.store'), [
                'name' => 'Senior Lecturer',
                'code' => 'sl',
                'description' => 'Academic teaching role',
                'status' => 'active',
            ], ['Referer' => route('designations.index')])
            ->assertRedirect(route('designations.index'))
            ->assertSessionHasNoErrors();

        $designation = Designation::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->assertSame('SL', $designation->code);

        $this->asCollege($college, $admin)
            ->post(route('faculties.store'), [
                'employee_code' => ' emp-001 ',
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'email' => 'grace@example.test',
                'alternate_phone' => '555-0102',
                'department_id' => $department->id,
                'designation_id' => $designation->id,
                'employment_type' => 'permanent',
                'joining_date' => '2026-01-15',
                'status' => 'active',
            ], ['Referer' => route('faculties.index')])
            ->assertRedirect(route('faculties.index'))
            ->assertSessionHasNoErrors();

        $employee = Faculty::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->assertSame('EMP-001', $employee->employee_code);
        $this->assertSame($designation->id, $employee->designation_id);
        $this->assertSame('Senior Lecturer', $employee->displayDesignation());
        $this->assertSame($department->id, $employee->department_id);

        $this->asCollege($college, $admin)
            ->put(route('faculties.update', $employee), [
                'employee_code' => 'EMP-001',
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'status' => 'inactive',
                'designation_id' => $designation->id,
            ], ['Referer' => route('faculties.edit', $employee)])
            ->assertRedirect(route('faculties.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('inactive', $employee->fresh()->status);

        $this->asCollege($college, $admin)
            ->delete(route('faculties.destroy', $employee), [], ['Referer' => route('faculties.index')])
            ->assertRedirect(route('faculties.index'));
        $this->assertSoftDeleted('faculties', ['id' => $employee->id]);
    }

    public function test_employee_code_and_designation_code_are_tenant_scoped_and_duplicate_safe(): void
    {
        $collegeA = $this->makeCollege('HRDUPA');
        $collegeB = $this->makeCollege('HRDUPB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['faculties.view', 'faculties.create', 'designations.view', 'designations.create']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['faculties.view', 'faculties.create', 'designations.view', 'designations.create']);

        $designation = Designation::create(['college_id' => $collegeA->id, 'name' => 'Manager', 'code' => 'MGR', 'status' => 'active']);
        Faculty::create(['college_id' => $collegeA->id, 'employee_code' => 'EMP-100', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'active']);

        $this->asCollege($collegeA, $adminA)->post(route('faculties.store'), [
            'employee_code' => 'emp-100', 'first_name' => 'Duplicate', 'last_name' => 'Person', 'status' => 'active',
        ], ['Referer' => route('faculties.index')])->assertSessionHasErrors('employee_code');

        $this->asCollege($collegeB, $adminB)->post(route('faculties.store'), [
            'employee_code' => 'EMP-100', 'first_name' => 'Other', 'last_name' => 'College', 'status' => 'active',
        ], ['Referer' => route('faculties.index')])->assertSessionHasNoErrors();

        $this->asCollege($collegeA, $adminA)->post(route('designations.store'), [
            'name' => 'Another Manager', 'code' => 'mgr', 'status' => 'active',
        ], ['Referer' => route('designations.index')])->assertSessionHasErrors('code');

        $this->asCollege($collegeB, $adminB)->post(route('designations.store'), [
            'name' => 'Manager', 'code' => 'MGR', 'status' => 'active',
        ], ['Referer' => route('designations.index')])->assertSessionHasNoErrors();

        $this->assertSame($collegeA->id, $designation->college_id);
    }

    public function test_hr_navigation_shows_only_the_granted_permission_gated_options(): void
    {
        $college = $this->makeCollege('HRNAV');
        $user = $this->makeUserWithPermissions($college, [
            'faculties.view', 'departments.view', 'designations.view', 'employee_documents.view',
        ]);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $start = strpos($html, '>HR / Staff Management</div>');
        $this->assertNotFalse($start);
        $end = strpos($html, 'uppercase tracking-widest', $start + 1);
        $group = substr($html, $start, $end === false ? null : $end - $start);

        $this->assertSame(4, substr_count($group, 'class="nav-link"'));
        foreach (['Staff / Employee', 'Staff Departments', 'Designations', 'Employee Documents'] as $label) {
            $this->assertStringContainsString($label, $group);
        }
    }

    public function test_hr_navigation_exposes_all_eight_options_only_with_actual_view_permissions(): void
    {
        $college = $this->makeCollege('HREIGHT');
        $user = $this->makeUserWithPermissions($college, [
            'faculties.view', 'departments.view', 'designations.view', 'employee_documents.view',
            'staff_attendance.view', 'leave_requests.view', 'salary_structures.view', 'hr_reports.view',
        ]);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $start = strpos($html, '>HR / Staff Management</div>');
        $this->assertNotFalse($start);
        $end = strpos($html, 'uppercase tracking-widest', $start + 1);
        $group = substr($html, $start, $end === false ? null : $end - $start);
        $this->assertSame(8, substr_count($group, 'class="nav-link"'));
        foreach (['Staff / Employee', 'Staff Departments', 'Designations', 'Employee Documents', 'Staff Attendance', 'Leave Management', 'Staff Salary / Payroll', 'HR Reports'] as $label) {
            $this->assertStringContainsString($label, $group);
        }
        $this->assertStringNotContainsString('employees.', $group);
    }

}
