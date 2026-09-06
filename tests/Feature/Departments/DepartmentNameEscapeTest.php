<?php

namespace Tests\Feature\Departments;

use App\Models\Department;
use Tests\TestCase;

class DepartmentNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('XSS');
        $admin = $this->makeUserWithPermissions($college, ['departments.view', 'departments.delete']); // delete perm so the confirm() form renders
        $malicious = "O'Brien');alert(1);('";
        Department::create(['college_id' => $college->id, 'name' => $malicious, 'code' => 'XSS1', 'status' => 'active']);

        $html = $this->asCollege($college, $admin)->get(route('departments.index'))->assertOk()->getContent();

        // Regression: the vulnerable template interpolated the name raw into the
        // confirm('…') JS string, so this payload broke out of the string literal
        // and executed on delete-click.
        $this->assertStringNotContainsString("');alert(1);('", $html);
        $this->assertStringNotContainsString("department O'", $html);

        // The name remains visible and fully intact: HTML-escaped in the table
        // cell, JS-escaped inside the confirmation handler (@js encoding).
        $this->assertStringContainsString('O&#039;Brien', $html);
        $this->assertStringContainsString('O\u0027Brien', $html);
    }
}
