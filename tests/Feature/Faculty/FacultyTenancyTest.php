<?php

namespace Tests\Feature\Faculty;

use App\Models\{College, Department, Faculty};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultyTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_faculty_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('FCIA');
        $collegeB = $this->makeCollege('FCIB');

        Faculty::create(['college_id' => $collegeA->id, 'employee_code' => 'EMP-A', 'first_name' => 'Alice', 'last_name' => 'Smith', 'status' => 'active']);
        Faculty::create(['college_id' => $collegeB->id, 'employee_code' => 'EMP-B', 'first_name' => 'Bob', 'last_name' => 'Jones', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculties.view']);

        $this->asCollege($collegeA, $adminA)->get(route('faculties.index'))
            ->assertSee('Alice Smith')
            ->assertDontSee('Bob Jones');
    }

    public function test_same_employee_code_across_colleges_is_allowed(): void
    {
        $collegeA = $this->makeCollege('FCDA');
        $collegeB = $this->makeCollege('FCDB');

        Faculty::create(['college_id' => $collegeA->id, 'employee_code' => 'EMP-1', 'first_name' => 'Alice', 'last_name' => 'Smith', 'status' => 'active']);

        $adminB = $this->makeUserWithPermissions($collegeB, ['faculties.view', 'faculties.create']);

        $this->asCollege($collegeB, $adminB)
            ->post(route('faculties.store'), [
                'employee_code' => 'EMP-1',
                'first_name' => 'Bob',
                'last_name' => 'Jones',
                'status' => 'active',
            ], ['Referer' => route('faculties.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, Faculty::withoutGlobalScopes()->where('employee_code', 'EMP-1')->count());
    }

    public function test_cross_college_department_is_rejected(): void
    {
        $collegeA = $this->makeCollege('FCDPA');
        $collegeB = $this->makeCollege('FCDPB');

        $deptB = Department::create(['college_id' => $collegeB->id, 'name' => 'Foreign Dept', 'code' => 'FD', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['faculties.view', 'faculties.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('faculties.store'), [
                'department_id' => $deptB->id,
                'employee_code' => 'EMP-X',
                'first_name' => 'Charlie',
                'last_name' => 'Brown',
                'status' => 'active',
            ], ['Referer' => route('faculties.index')])
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('faculties', ['employee_code' => 'EMP-X']);
    }

    public function test_cross_college_faculty_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('FCOCA');
        $collegeB = $this->makeCollege('FCOCB');

        $foreign = Faculty::create(['college_id' => $collegeB->id, 'employee_code' => 'FFAC', 'first_name' => 'Foreign', 'last_name' => 'Faculty', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculties.view', 'faculties.update', 'faculties.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('faculties.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('faculties.update', $foreign), [
            'employee_code' => 'FFAC',
            'first_name' => 'Hijacked',
            'last_name' => 'Faculty',
            'status' => 'active',
        ], ['Referer' => route('faculties.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('faculties.destroy', $foreign), [], ['Referer' => route('faculties.index')])->assertNotFound();

        $this->assertSame('Foreign', $foreign->fresh()->first_name);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('FCSPA');
        $collegeB = $this->makeCollege('FCSPB');

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculties.view', 'faculties.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('faculties.store'), [
                'college_id' => $collegeB->id,
                'employee_code' => 'SNK-FAC',
                'first_name' => 'Sneaky',
                'last_name' => 'Staff',
                'status' => 'active',
            ], ['Referer' => route('faculties.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('faculties', ['college_id' => $collegeA->id, 'employee_code' => 'SNK-FAC']);
        $this->assertDatabaseMissing('faculties', ['college_id' => $collegeB->id, 'employee_code' => 'SNK-FAC']);
    }
}
