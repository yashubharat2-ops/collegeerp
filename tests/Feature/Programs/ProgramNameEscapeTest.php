<?php

namespace Tests\Feature\Programs;

use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ProgramNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('XSS');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.delete']); // delete perm so the confirm() form renders
        $malicious = "O'Brien');alert(1);('";
        Program::create(['college_id' => $college->id, 'name' => $malicious, 'code' => 'XSS1', 'status' => 'active']);

        $html = $this->asCollege($college, $admin)->get(route('programs.index'))->assertOk()->getContent();

        // Regression: a vulnerable template would interpolate the name raw into the
        // confirm('…') JS string, letting this payload break out of the string
        // literal and execute on delete-click. The @js encoding must prevent that.
        $this->assertStringNotContainsString("');alert(1);('", $html);
        $this->assertStringNotContainsString("program O'", $html);
        $this->assertStringNotContainsString('<script', $html);

        // The name remains visible and fully intact: HTML-escaped in the table
        // cell, JS-escaped inside the confirmation handler (@js encoding).
        $this->assertStringContainsString('O&#039;Brien', $html);
        $this->assertStringContainsString('O\\u0027Brien', $html);
    }
}
