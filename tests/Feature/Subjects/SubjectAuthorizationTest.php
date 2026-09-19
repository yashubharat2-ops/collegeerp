<?php

namespace Tests\Feature\Subjects;

use App\Models\{Role, Subject, User};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SubjectAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('SUBFB');
        $outsider = $this->makeUserWithPermissions($college, []);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Math', 'code' => 'MTH', 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('subjects.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('subjects.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('subjects.store'), ['name' => 'Physics', 'code' => 'PHY', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('subjects.edit', $subject))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('subjects.update', $subject), ['name' => 'Math Mod', 'code' => 'MTH', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('subjects.destroy', $subject))->assertForbidden();
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('SUBEX');
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Math', 'code' => 'MTH', 'status' => 'active']);

        $creator = $this->makeUserWithPermissions($college, ['subjects.create']);
        $this->asCollege($college, $creator)->get(route('subjects.index'))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('subjects.store'), [
            'name' => 'Physics',
            'code' => 'PHY',
            'status' => 'active',
        ], ['Referer' => route('subjects.index')])->assertSessionHas('success');

        $viewer = $this->makeUserWithPermissions($college, ['subjects.view']);
        $this->asCollege($college, $viewer)->get(route('subjects.index'))->assertOk()->assertSee('Math');
        $this->asCollege($college, $viewer)->get(route('subjects.create'))->assertForbidden();

        $editor = $this->makeUserWithPermissions($college, ['subjects.update']);
        $this->asCollege($college, $editor)->get(route('subjects.edit', $subject))->assertOk();
        $this->asCollege($college, $editor)->put(route('subjects.update', $subject), [
            'name' => 'Applied Math',
            'code' => 'MTH',
            'status' => 'active',
        ], ['Referer' => route('subjects.edit', $subject)])->assertSessionHas('success');

        $deleter = $this->makeUserWithPermissions($college, ['subjects.delete']);
        $this->asCollege($college, $deleter)->delete(route('subjects.destroy', $subject), [], ['Referer' => route('subjects.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('subjects', ['id' => $subject->id]);
    }
}
