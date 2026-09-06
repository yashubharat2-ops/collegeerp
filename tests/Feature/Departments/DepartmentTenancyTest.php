<?php

namespace Tests\Feature\Departments;

use App\Models\{Campus, College, Department};
use Tests\TestCase;

class DepartmentTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_departments_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('ISOA');
        $collegeB = $this->makeCollege('ISOB');
        Department::create(['college_id' => $collegeA->id, 'name' => 'Alpha Dept', 'code' => 'AD', 'status' => 'active']);
        Department::create(['college_id' => $collegeB->id, 'name' => 'Bravo Dept', 'code' => 'AD', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['departments.view']);

        $this->asCollege($collegeA, $adminA)->get(route('departments.index'))
            ->assertSee('Alpha Dept')
            ->assertDontSee('Bravo Dept');
    }

    public function test_same_code_in_another_college_is_allowed(): void
    {
        $collegeA = $this->makeCollege('DUPA');
        $collegeB = $this->makeCollege('DUPB');
        Department::create(['college_id' => $collegeA->id, 'name' => 'Physics', 'code' => 'PHY', 'status' => 'active']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['departments.view', 'departments.create']);

        $this->asCollege($collegeB, $adminB)
            ->post(route('departments.store'), ['name' => 'Physics', 'code' => 'PHY', 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('departments', ['college_id' => $collegeB->id, 'name' => 'Physics', 'code' => 'PHY']);
        $this->assertSame(2, Department::withoutGlobalScopes()->where('code', 'PHY')->count());
    }

    public function test_cross_college_records_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('XSCA');
        $collegeB = $this->makeCollege('XSCB');
        $foreign = Department::create(['college_id' => $collegeB->id, 'name' => 'Foreign Dept', 'code' => 'FRG', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['departments.view', 'departments.update', 'departments.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('departments.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('departments.update', $foreign), ['name' => 'Hijacked', 'code' => 'FRG', 'status' => 'active'], ['Referer' => route('departments.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->patch(route('departments.status', $foreign), ['status' => 'inactive'], ['Referer' => route('departments.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('departments.destroy', $foreign), [], ['Referer' => route('departments.index')])->assertNotFound();

        $this->assertSame('Foreign Dept', $foreign->fresh()->name);
        $this->assertSame('active', $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_the_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SPYA');
        $collegeB = $this->makeCollege('SPYB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['departments.view', 'departments.create']);

        // The attacker (admin of A) tries to plant a row in college B.
        $this->asCollege($collegeA, $adminA)
            ->post(route('departments.store'), ['college_id' => $collegeB->id, 'name' => 'Sneaky Dept', 'code' => 'SNK', 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departments', ['college_id' => $collegeA->id, 'name' => 'Sneaky Dept']);
        $this->assertDatabaseMissing('departments', ['college_id' => $collegeB->id, 'name' => 'Sneaky Dept']);
    }

    public function test_campus_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('CMPA');
        $collegeB = $this->makeCollege('CMPB');
        $campusB = Campus::create(['college_id' => $collegeB->id, 'name' => 'B North', 'code' => 'BN', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['departments.view', 'departments.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('departments.store'), ['name' => 'Engineering', 'code' => 'ENG', 'campus_id' => $campusB->id, 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasErrors('campus_id');

        // A college-level department (no campus) is allowed.
        $this->asCollege($collegeA, $adminA)
            ->post(route('departments.store'), ['name' => 'Engineering', 'code' => 'ENG', 'status' => 'active'], ['Referer' => route('departments.index')])
            ->assertSessionHasNoErrors();
        $this->assertNull(Department::withoutGlobalScopes()->firstWhere('code', 'ENG')->campus_id);
    }

    public function test_permissions_are_resolved_per_college(): void
    {
        $collegeA = $this->makeCollege('PRVA');
        $collegeB = $this->makeCollege('PRVB');
        // One user, member of both colleges, admin of B only.
        $user = $this->makeUserWithPermissions($collegeB, ['departments.view']);
        $user->colleges()->attach($collegeA->id);

        $this->asCollege($collegeB, $user)->get(route('departments.index'))->assertOk();
        // Same user, acting in college A where they hold no role: forbidden.
        $this->asCollege($collegeA, $user)->get(route('departments.index'))->assertForbidden();
    }
}
