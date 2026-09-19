<?php

namespace Tests\Feature\Faculty;

use App\Models\Faculty;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultyNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('FCXS');
        $admin = $this->makeUserWithPermissions($college, ['faculties.view', 'faculties.delete']);

        $malicious = "O'Connor');alert('xss');('";
        Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'XSS-FAC',
            'first_name' => 'Brian',
            'last_name' => $malicious,
            'status' => 'active',
        ]);

        $html = $this->asCollege($college, $admin)->get(route('faculties.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("');alert('xss');('", $html);
        $this->assertStringContainsString('O&#039;Connor', $html);
    }
}
