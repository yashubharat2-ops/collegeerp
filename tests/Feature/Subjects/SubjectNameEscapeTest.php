<?php

namespace Tests\Feature\Subjects;

use App\Models\Subject;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SubjectNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('SBXS');
        $admin = $this->makeUserWithPermissions($college, ['subjects.view', 'subjects.delete']);

        $malicious = "Mathematics');alert('xss');('";
        Subject::create([
            'college_id' => $college->id,
            'name' => $malicious,
            'code' => 'XSS-SUB',
            'status' => 'active',
        ]);

        $html = $this->asCollege($college, $admin)->get(route('subjects.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("');alert('xss');('", $html);
        $this->assertStringContainsString('Mathematics&#039;);alert(&#039;xss&#039;);(&#039;', $html);
    }
}
