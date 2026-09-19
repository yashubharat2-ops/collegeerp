<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\{AcademicTerm, AcademicYear};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AcademicTermNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('ATXS');
        $admin = $this->makeUserWithPermissions($college, ['academic_terms.view', 'academic_terms.delete']);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $malicious = "Semester 1');alert('xss');('";
        AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => $malicious,
            'code' => 'XSS-TERM',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $html = $this->asCollege($college, $admin)->get(route('academic-terms.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("');alert('xss');('", $html);
        $this->assertStringContainsString('Semester 1&#039;);alert(&#039;xss&#039;);(&#039;', $html);
    }
}
