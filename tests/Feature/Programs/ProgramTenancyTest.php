<?php

namespace Tests\Feature\Programs;

use App\Models\{Department, Program};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ProgramTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_programs_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('ISOA');
        $collegeB = $this->makeCollege('ISOB');
        Program::create(['college_id' => $collegeA->id, 'name' => 'Alpha Program', 'code' => 'AP', 'status' => 'active']);
        Program::create(['college_id' => $collegeB->id, 'name' => 'Bravo Program', 'code' => 'BP', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['programs.view']);

        $this->asCollege($collegeA, $adminA)->get(route('programs.index'))
            ->assertOk()
            ->assertSee('Alpha Program')
            ->assertDontSee('Bravo Program');
    }

    public function test_same_name_and_code_in_another_college_is_allowed(): void
    {
        $collegeA = $this->makeCollege('DUPA');
        $collegeB = $this->makeCollege('DUPB');
        Program::create(['college_id' => $collegeA->id, 'name' => 'Physics', 'code' => 'PHY', 'status' => 'active']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['programs.view', 'programs.create']);

        $this->asCollege($collegeB, $adminB)
            ->post(route('programs.store'), ['name' => 'Physics', 'code' => 'PHY', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('programs', ['college_id' => $collegeB->id, 'name' => 'Physics', 'code' => 'PHY']);
        $this->assertSame(2, Program::withoutGlobalScopes()->where('code', 'PHY')->count());
    }

    public function test_cross_college_programs_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('XSCA');
        $collegeB = $this->makeCollege('XSCB');
        $foreign = Program::create(['college_id' => $collegeB->id, 'name' => 'Foreign Program', 'code' => 'FRG', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['programs.view', 'programs.update', 'programs.delete']);

        $edit = $this->asCollege($collegeA, $adminA)->get(route('programs.edit', $foreign));
        $edit->assertNotFound();
        // The 404 must not leak any information about the foreign row.
        $this->assertStringNotContainsString('Foreign Program', $edit->getContent());

        $this->asCollege($collegeA, $adminA)
            ->put(route('programs.update', $foreign), ['name' => 'Hijacked', 'code' => 'FRG', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->delete(route('programs.destroy', $foreign), [], ['Referer' => route('programs.index')])
            ->assertNotFound();

        $foreign->refresh();
        $this->assertSame('Foreign Program', $foreign->name);
        $this->assertSame('active', $foreign->status);
        $this->assertNull($foreign->deleted_at);
    }

    public function test_college_id_from_the_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SPYA');
        $collegeB = $this->makeCollege('SPYB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['programs.view', 'programs.create']);

        // The attacker (admin of A) tries to plant a row in college B.
        $this->asCollege($collegeA, $adminA)
            ->post(route('programs.store'), ['college_id' => $collegeB->id, 'name' => 'Sneaky Program', 'code' => 'SNK', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('programs', ['college_id' => $collegeA->id, 'name' => 'Sneaky Program']);
        $this->assertDatabaseMissing('programs', ['college_id' => $collegeB->id, 'name' => 'Sneaky Program']);
    }

    public function test_department_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('CMPA');
        $collegeB = $this->makeCollege('CMPB');
        $departmentB = Department::create(['college_id' => $collegeB->id, 'name' => 'B Sciences', 'code' => 'BS', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['programs.view', 'programs.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('programs.store'), ['name' => 'Engineering', 'code' => 'ENG', 'department_id' => $departmentB->id, 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasErrors('department_id');

        // A college-level program (no department) is allowed.
        $this->asCollege($collegeA, $adminA)
            ->post(route('programs.store'), ['name' => 'Engineering', 'code' => 'ENG', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasNoErrors();
        $this->assertNull(Program::withoutGlobalScopes()->firstWhere('code', 'ENG')->department_id);
    }
}
