<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private function sidebar(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_every_visible_module_has_a_closed_native_toggle_with_its_original_links(): void
    {
        $college = $this->makeCollege('SIDEBAR1');
        $user = $this->makeSuperAdmin($college);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $xpath = $this->sidebar($html);
        $sections = $xpath->query('//aside/nav/details[@data-sidebar-section]');
        $titles = [];
        foreach ($sections as $section) {
            $titles[] = trim($xpath->query('./summary/div', $section)->item(0)->textContent);
            $this->assertFalse($section->hasAttribute('open'), 'Sections start closed on the dashboard.');
            $this->assertSame(1, $xpath->query('./summary', $section)->length, 'Native summary is keyboard accessible.');
            $this->assertSame(1, $xpath->query('./summary/svg', $section)->length, 'Each module has the same custom chevron.');
            $this->assertGreaterThan(0, $xpath->query('./div[@data-sidebar-links]//a[@href]', $section)->length);
        }
        $this->assertSame([
            'Platform', 'HR / Staff Management', 'Admissions', 'Students', 'CERTIFICATE MANAGEMENT (EC)',
            'Academics', 'Examinations', 'Finance / Fees', 'Transport Management', 'Library Management',
            'Hostel Management', 'Communication Management', 'Inventory / Asset Management', 'REPORTS',
            'ADMINISTRATION / SETTINGS',
        ], $titles);
        $this->assertStringContainsString('js/sidebar.js', $html);
        $this->assertSame(1, $xpath->query('//button[@data-sidebar-toggle and @aria-controls="app-sidebar" and @type="button"]')->length);
        $this->assertSame(1, $xpath->query('//aside/nav/a[@href="'.route('dashboard').'"]')->length);
        $this->assertSame(1, $xpath->query('//aside/nav/details[summary/div="Students"]//a[@href="'.route('students.index').'"]')->length);
        $this->assertSame(1, $xpath->query('//aside/nav/details[summary/div="REPORTS"]//a[@href="'.route('student-reports.index').'"]')->length);
    }

    public function test_administration_active_link_is_preserved_for_named_and_legacy_settings_pages(): void
    {
        $college = $this->makeCollege('SIDEBAR3');
        $user = $this->makeSuperAdmin($college);
        foreach ([route('admin.institution-settings.index'), route('settings.index')] as $url) {
            $html = $this->asCollege($college, $user)->get($url)->assertOk()->getContent();
            $xpath = $this->sidebar($html);
            $this->assertSame(1, $xpath->query('//aside/nav/details[summary/div="ADMINISTRATION / SETTINGS"]//a[@aria-current="page" and @href="'.route('admin.institution-settings.index').'"]')->length);
        }
    }

    public function test_permission_filtered_sections_and_links_are_not_resurrected_by_the_toggle(): void
    {
        $college = $this->makeCollege('SIDEBAR2');
        $user = $this->makeUserWithPermissions($college, ['exam_marks.view']);
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $xpath = $this->sidebar($html);
        $this->assertSame(1, $xpath->query('//aside/nav/details[summary/div="Examinations"]//a[@href="'.route('exam-marks.index').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside/nav/details[summary/div="Examinations"]//a[@href="'.route('results.index').'"]')->length);
        foreach (['Finance / Fees', 'Library Management', 'REPORTS', 'ADMINISTRATION / SETTINGS'] as $title) {
            $this->assertSame(0, $xpath->query('//aside/nav/details[summary/div="'.$title.'"]')->length);
        }
    }
}
