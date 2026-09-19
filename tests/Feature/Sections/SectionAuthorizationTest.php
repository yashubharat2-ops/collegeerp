<?php

namespace Tests\Feature\Sections;

use App\Models\{AcademicYear, Program, Role, Section, User};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SectionAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('SECFB');
        $outsider = $this->makeUserWithPermissions($college, []);
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'CS', 'code' => 'CS', 'status' => 'active']);
        $section = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Sec A', 'code' => 'A', 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('sections.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('sections.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('sections.store'), ['academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Sec B', 'code' => 'B', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('sections.edit', $section))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('sections.update', $section), ['academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Sec A Mod', 'code' => 'A', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('sections.destroy', $section))->assertForbidden();
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('SECEX');
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'CS', 'code' => 'CS', 'status' => 'active']);
        $section = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Sec A', 'code' => 'A', 'status' => 'active']);

        $creator = $this->makeUserWithPermissions($college, ['sections.create']);
        $this->asCollege($college, $creator)->get(route('sections.index'))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('sections.store'), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => 'Sec B',
            'code' => 'B',
            'status' => 'active',
        ], ['Referer' => route('sections.index')])->assertSessionHas('success');

        $viewer = $this->makeUserWithPermissions($college, ['sections.view']);
        $this->asCollege($college, $viewer)->get(route('sections.index'))->assertOk()->assertSee('Sec A');
        $this->asCollege($college, $viewer)->get(route('sections.create'))->assertForbidden();

        $editor = $this->makeUserWithPermissions($college, ['sections.update']);
        $this->asCollege($college, $editor)->get(route('sections.edit', $section))->assertOk();
        $this->asCollege($college, $editor)->put(route('sections.update', $section), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => 'Sec A Renamed',
            'code' => 'A',
            'status' => 'active',
        ], ['Referer' => route('sections.edit', $section)])->assertSessionHas('success');

        $deleter = $this->makeUserWithPermissions($college, ['sections.delete']);
        $this->asCollege($college, $deleter)->delete(route('sections.destroy', $section), [], ['Referer' => route('sections.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('sections', ['id' => $section->id]);
    }
}
