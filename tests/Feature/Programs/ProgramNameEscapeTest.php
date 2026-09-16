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

        // Laravel/Vite legitimately renders <script type="module" src="…"> tags for the
        // compiled asset bundles, so "the page must not contain <script at all" is the
        // wrong assertion. What the payload must never manage is adding a script tag of
        // its own: every <script> in the response has to be a Vite module tag loading an
        // external .js bundle (no inline code, no attacker-controlled src).
        preg_match_all('/<script\b[^>]*>/', $html, $scriptTags);
        $this->assertNotEmpty($scriptTags[0], 'Expected the layout to render the Vite asset script tags.');
        foreach ($scriptTags[0] as $scriptTag) {
            $this->assertMatchesRegularExpression('/^<script\b(?=[^>]*\btype="module")(?=[^>]*\bsrc="[^"]+\.js")[^>]*>$/', $scriptTag, 'Program name injected a script tag: '.$scriptTag);
        }

        // The name reaches JavaScript only inside the confirm('…') string of the delete
        // form's onsubmit attribute, so it must stay inside that quoted literal: the @js
        // encoding has to turn the payload's quotes into \u0027, leaving no raw quote
        // that could terminate the string and start executing attacker JavaScript.
        $this->assertSame(1, preg_match('/onsubmit="return confirm\(\'([^"]*)\'\)/', $html, $confirm), 'Expected the delete form to render a confirm() handler.');
        $this->assertStringNotContainsString("'", $confirm[1], 'Program name broke out of the confirm() JS string literal.');

        // The name remains visible and fully intact: HTML-escaped in the table
        // cell, JS-escaped inside the confirmation handler (@js encoding).
        $this->assertStringContainsString('O&#039;Brien', $html);
        $this->assertStringContainsString('O\\u0027Brien', $html);
    }
}
