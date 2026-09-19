<?php

namespace Tests\Feature\Faculty;

use App\Models\{Faculty, Role, User};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultyAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('FACFB');
        $outsider = $this->makeUserWithPermissions($college, []);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'EMP-01', 'first_name' => 'John', 'last_name' => 'Doe', 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('faculties.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('faculties.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('faculties.store'), ['employee_code' => 'EMP-02', 'first_name' => 'Jane', 'last_name' => 'Doe', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('faculties.edit', $faculty))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('faculties.update', $faculty), ['employee_code' => 'EMP-01', 'first_name' => 'John', 'last_name' => 'Mod', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('faculties.destroy', $faculty))->assertForbidden();
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('FACEX');
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'EMP-01', 'first_name' => 'John', 'last_name' => 'Doe', 'status' => 'active']);

        $creator = $this->makeUserWithPermissions($college, ['faculties.create']);
        $this->asCollege($college, $creator)->get(route('faculties.index'))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('faculties.store'), [
            'employee_code' => 'EMP-02',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
        ], ['Referer' => route('faculties.index')])->assertSessionHas('success');

        $viewer = $this->makeUserWithPermissions($college, ['faculties.view']);
        $this->asCollege($college, $viewer)->get(route('faculties.index'))->assertOk()->assertSee('John Doe');
        $this->asCollege($college, $viewer)->get(route('faculties.create'))->assertForbidden();

        $editor = $this->makeUserWithPermissions($college, ['faculties.update']);
        $this->asCollege($college, $editor)->get(route('faculties.edit', $faculty))->assertOk();
        $this->asCollege($college, $editor)->put(route('faculties.update', $faculty), [
            'employee_code' => 'EMP-01',
            'first_name' => 'Jonathan',
            'last_name' => 'Doe',
            'status' => 'active',
        ], ['Referer' => route('faculties.edit', $faculty)])->assertSessionHas('success');

        $deleter = $this->makeUserWithPermissions($college, ['faculties.delete']);
        $this->asCollege($college, $deleter)->delete(route('faculties.destroy', $faculty), [], ['Referer' => route('faculties.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('faculties', ['id' => $faculty->id]);
    }
}
