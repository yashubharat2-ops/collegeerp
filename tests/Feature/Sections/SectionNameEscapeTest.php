<?php

namespace Tests\Feature\Sections;

use App\Models\{AcademicYear, Program, Section};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SectionNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('SECXS');
        $admin = $this->makeUserWithPermissions($college, ['sections.view', 'sections.delete']);
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'CS', 'code' => 'CS', 'status' => 'active']);

        $malicious = "Batch A');alert('xss');('";
        Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => $malicious,
            'code' => 'XSS-SEC',
            'status' => 'active',
        ]);

        $html = $this->asCollege($college, $admin)->get(route('sections.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("');alert('xss');('", $html);
        $this->assertStringContainsString('Batch A&#039;);alert(&#039;xss&#039;);(&#039;', $html);
    }
}
