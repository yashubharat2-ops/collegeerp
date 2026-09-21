<?php

namespace Tests\Feature\Examinations;

use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

/**
 * Regression suite for the sidebar navigation — Examinations Phase 3A.
 *
 * Guards that Phase 3A (Grade / Pass-Fail) is rendered inside the single
 * existing Examinations section, gated on grade_scales.view, without
 * duplicating headings and without introducing Results / Calculation /
 * Publishing or Phase 4 items.
 */
class ExaminationsNavigationTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    /** Every .view permission that unlocks an Examinations sidebar entry for Phase 3A. */
    private const ALL_EXAM_VIEW_PERMISSIONS = [
        'examinations.view',
        'exam_schedules.view',
        'exam_attendance.view',
        'exam_marks.view',
        'grade_scales.view',
    ];

    /** The five labels the single Examinations group must contain for Phase 3A. */
    private const EXPECTED_NAV_LINKS = [
        'examinations.index' => 'Examinations',
        'exam-schedules.index' => 'Exam Schedule',
        'exam-attendance.index' => 'Exam Attendance',
        'exam-marks.index' => 'Marks Entry',
        'grade-scales.index' => 'Grade / Pass-Fail',
    ];

    /**
     * The Examinations sidebar group: from its heading until the next
     * group heading (same extraction technique used by the Students
     * sidebar tests).
     */
    private function examinationsNavGroup(string $html): string
    {
        $start = strpos($html, '>Examinations</div>');
        $this->assertNotFalse($start, 'The sidebar must have an Examinations group heading.');

        $after = $start + strlen('>Examinations</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_examinations_group_lists_all_five_items_under_one_section(): void
    {
        $college = $this->makeCollege('EXNAV1');
        $user = $this->makeUserWithPermissions($college, self::ALL_EXAM_VIEW_PERMISSIONS);

        $response = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        // Exactly one Examinations section heading — no separate top-level
        // section and no duplicated heading.
        $this->assertSame(1, substr_count($html, '>Examinations</div>'),
            'There must be exactly one Examinations sidebar section.');

        $group = $this->examinationsNavGroup($html);
        $this->assertSame(5, substr_count($group, 'class="nav-link"'),
            'The Examinations group must contain exactly 5 entries for Phase 3A.');

        foreach (self::EXPECTED_NAV_LINKS as $route => $label) {
            $url = route($route);
            $this->assertStringContainsString($url, $group, "Missing link to {$url}.");
            $this->assertStringContainsString($label, $group, "Missing label {$label}.");
        }

        // No duplicated entries and no Phase 3 (Results) or Phase 4 items.
        foreach (self::EXPECTED_NAV_LINKS as $label) {
            $this->assertSame(1, substr_count($group, $label), "Duplicated sidebar entry: {$label}");
        }

        foreach (['Results', 'Result Calculation', 'Result Publishing', 'Marksheet', 'Grade Card', 'Certificates', 'Ranking', 'Merit List'] as $future) {
            // Grade / Pass-Fail is allowed; the others must not appear.
            if ($future === 'Grade / Pass-Fail') {
                continue;
            }
            $this->assertStringNotContainsString($future, $group, "Phase 3 (full) / Phase 4 item must not appear in Phase 3A: {$future}");
        }
    }

    public function test_phase_three_a_grade_link_is_gated_on_its_own_permission(): void
    {
        $college = $this->makeCollege('EXNAV2');

        // Without the permission the entry is absent…
        $without = $this->makeUserWithPermissions($college, ['students.view']);
        $this->asCollege($college, $without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('grade-scales.index'), false)
            ->assertDontSee('Grade / Pass-Fail')
            ->assertDontSee('>Examinations</div>', false);

        // …and with only that permission exactly one entry appears.
        $with = $this->makeUserWithPermissions($college, ['grade_scales.view']);
        $html = $this->asCollege($college, $with)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Examinations</div>'), 'One heading for grade_scales.view.');

        $group = $this->examinationsNavGroup($html);

        $this->assertSame(1, substr_count($group, 'class="nav-link"'), 'Exactly one entry for grade_scales.view.');
        $this->assertStringContainsString(route('grade-scales.index'), $group);
        $this->assertStringContainsString('Grade / Pass-Fail', $group);
    }

    public function test_phase_two_links_respect_rbac_permissions(): void
    {
        $college = $this->makeCollege('EXNAV3');

        $attendanceOnly = $this->makeUserWithPermissions($college, ['exam_attendance.view']);
        $this->asCollege($college, $attendanceOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('exam-attendance.index'), false)
            ->assertSee('Exam Attendance')
            ->assertDontSee(route('exam-marks.index'), false)
            ->assertDontSee('Marks Entry')
            ->assertDontSee(route('grade-scales.index'), false)
            ->assertDontSee('Grade / Pass-Fail')
            ->assertDontSee(route('exam-schedules.index'), false)
            ->assertDontSee(route('examinations.index'), false);

        $marksOnly = $this->makeUserWithPermissions($college, ['exam_marks.view']);
        $this->asCollege($college, $marksOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('exam-marks.index'), false)
            ->assertSee('Marks Entry')
            ->assertDontSee(route('exam-attendance.index'), false)
            ->assertDontSee('Exam Attendance')
            ->assertDontSee(route('grade-scales.index'), false)
            ->assertDontSee('Grade / Pass-Fail')
            ->assertDontSee(route('exam-schedules.index'), false)
            ->assertDontSee(route('examinations.index'), false);
    }

    public function test_examinations_section_is_hidden_without_examination_permissions(): void
    {
        $college = $this->makeCollege('EXNAV4');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Examinations</div>', false)
            ->assertDontSee('Exam Attendance')
            ->assertDontSee('Marks Entry')
            ->assertDontSee('Grade / Pass-Fail');
    }

    public function test_super_admin_sees_the_full_examinations_group(): void
    {
        $college = $this->makeCollege('EXNAV5');
        $super = $this->makeSuperAdmin($college);

        $response = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk();
        $group = $this->examinationsNavGroup($response->getContent());

        $this->assertSame(5, substr_count($group, 'class="nav-link"'),
            'Super Admin must see the complete Examinations group for Phase 3A.');

        foreach (self::EXPECTED_NAV_LINKS as $route => $label) {
            $this->assertStringContainsString(route($route), $group);
            $this->assertStringContainsString($label, $group);
        }
    }

    public function test_no_second_examinations_menu_and_no_future_items(): void
    {
        $college = $this->makeCollege('EXNAV6');
        $user = $this->makeUserWithPermissions($college, ['grade_scales.view']);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        // A single <aside> sidebar and a single Examinations heading.
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');
        $this->assertSame(1, substr_count($html, '>Examinations</div>'));

        // The group is a flat list of nav-links: no nested submenu container.
        $this->assertStringNotContainsString('Examinations Submenu', $html);
        $this->assertStringNotContainsString('data-collapse', $html);

        foreach (['Results', 'Result Calculation', 'Result Publishing', 'Marksheet', 'Grade Card'] as $future) {
            $this->assertStringNotContainsString($future, $html, "Future item must not appear: {$future}");
        }
    }
}
