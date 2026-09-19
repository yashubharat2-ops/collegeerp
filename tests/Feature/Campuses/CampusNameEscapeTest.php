<?php

namespace Tests\Feature\Campuses;

use App\Models\Campus;
use Tests\TestCase;

class CampusNameEscapeTest extends TestCase
{
    use CampusTestHelpers;

    public function test_campus_name_is_escaped_in_index_and_edit(): void
    {
        $college = $this->makeCollege('CESC');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create']);
        $xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => $xss, 'code' => 'XSS', 'status' => 'active']);

        $index = $this->asCollege($college, $admin)->get(route('campuses.index'));
        $index->assertOk();
        $index->assertDontSee($xss, false);
        $index->assertSee(e($xss), false);

        $admin2 = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.update']);
        $edit = $this->asCollege($college, $admin2)->get(route('campuses.edit', $campus));
        $edit->assertOk();
        // In edit form, value should be escaped via old() and Blade escaping
        $edit->assertDontSee($xss, false);
    }

    public function test_delete_confirmation_uses_safe_js_escaping(): void
    {
        $college = $this->makeCollege('CJS');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.delete']);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => "Test'\" <b> Campus", 'code' => 'JS', 'status' => 'active']);

        $response = $this->asCollege($college, $admin)->get(route('campuses.index'));
        $response->assertOk();
        // The delete form should use @js() which safely JSON-encodes the name
        // Check that the page contains confirm with safe escaping, not raw user input breaking JS
        $response->assertSee('Delete campus', false);
        $response->assertDontSee("Test'\" <b> Campus';", false); // raw would break JS
    }
}
