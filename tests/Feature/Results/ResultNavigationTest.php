<?php

namespace Tests\Feature\Results;

use Tests\TestCase;

/**
 * Sidebar navigation regression for Examinations Phase 3.
 *
 * Guards the invariants the phase depends on:
 *   - exactly ONE Examinations section (no second menu, no duplicate heading)
 *   - all eight entries, each exactly once
 *   - every Phase 3 entry gated on its own .view permission
 *   - no Phase 4 item leaks into the group
 */
class ResultNavigationTest extends TestCase
{
    use ResultTestHelpers;

    /**
     * The eight labels the single Examinations group must contain, in the order
     * they are rendered.
     */
    private const EXPECTED = [
        'examinations.index' => 'Examinations',
        'exam-schedules.index' => 'Exam Schedule',
        'exam-attendance.index' => 'Exam Attendance',
        'exam-marks.index' => 'Marks Entry',
        'results.index' => 'Results',
        'result-calculation.index' => 'Result Calculation',
        'grade-scales.index' => 'Grade / Pass-Fail',
        'result-publishing.index' => 'Result Publishing',
    ];

    /** Phase 4+ modules that must NOT appear anywhere in the sidebar. */
    private const FUTURE_ITEMS = [
        'Marksheet',
        'Grade Card',
        'Certificates',
        'Ranking',
        'Merit List',
        'Result Reports',
    ];

    /**
     * The Examinations sidebar group: from its heading until the next group
     * heading (same extraction technique used by the Students sidebar tests).
     */
    private function examinationsNavGroup(string $html): string
    {
        $start = strpos($html, '>Examinations</div>');
        $this->assertNotFalse($start, 'The sidebar must have an Examinations group heading.');

        $after = $start + strlen('>Examinations</div>');
        $end = strpos($html, 'uppercase tracking-widest', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }

    public function test_exactly_one_examinations_section_holding_all_eight_items(): void
    {
        $college = $this->makeCollege('RNAV1');
        $user = $this->makeUserWithPermissions($college, [
            'examinations.view',
            'exam_schedules.view',
            'exam_attendance.view',
            'exam_marks.view',
            'results.view',
            'result_calculation.view',
            'grade_scales.view',
            'result_publishing.view',
        ]);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Examinations</div>'),
            'There must be exactly one Examinations sidebar section.');

        $group = $this->examinationsNavGroup($html);

        $this->assertSame(8, substr_count($group, 'class="nav-link"'),
            'The Examinations group must contain exactly 8 entries.');

        foreach (self::EXPECTED as $route => $label) {
            $this->assertStringContainsString(route($route), $group, "Missing link {$route}.");
            $this->assertStringContainsString($label, $group, "Missing label {$label}.");
            $this->assertSame(1, substr_count($group, $label), "Duplicated sidebar entry: {$label}");
        }

        foreach (self::FUTURE_ITEMS as $future) {
            $this->assertStringNotContainsString($future, $html, "Phase 4 item must not appear: {$future}");
        }
    }

    public function test_each_phase_three_item_is_gated_on_its_own_view_permission(): void
    {
        $cases = [
            'results.view' => ['results.index', 'Results'],
            'result_calculation.view' => ['result-calculation.index', 'Result Calculation'],
            'grade_scales.view' => ['grade-scales.index', 'Grade / Pass-Fail'],
            'result_publishing.view' => ['result-publishing.index', 'Result Publishing'],
        ];

        foreach ($cases as $permission => [$route, $label]) {
            $college = $this->makeCollege('RNV'.substr(md5($permission), 0, 4));

            // Without the permission the entry is absent…
            $without = $this->makeUserWithPermissions($college, ['students.view']);
            $this->asCollege($college, $without)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee(route($route), false)
                ->assertDontSee($label);

            // …and with only that permission exactly one entry appears.
            $with = $this->makeUserWithPermissions($college, [$permission]);
            $html = $this->asCollege($college, $with)->get(route('dashboard'))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '>Examinations</div>'), "One heading for {$permission}.");

            $group = $this->examinationsNavGroup($html);

            $this->assertSame(1, substr_count($group, 'class="nav-link"'), "Exactly one entry for {$permission}.");
            $this->assertStringContainsString(route($route), $group);
            $this->assertStringContainsString($label, $group);
        }
    }

    public function test_the_examinations_group_is_hidden_without_any_examination_permission(): void
    {
        $college = $this->makeCollege('RNAV9');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Examinations</div>', false)
            ->assertDontSee('Result Publishing')
            ->assertDontSee('Grade / Pass-Fail');
    }

    public function test_super_admin_sees_the_complete_eight_item_group(): void
    {
        $college = $this->makeCollege('RNAVA');

        $html = $this->asCollege($college, $this->makeSuperAdmin($college))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $group = $this->examinationsNavGroup($html);

        $this->assertSame(8, substr_count($group, 'class="nav-link"'),
            'Super Admin must see the complete Examinations group.');

        foreach (self::EXPECTED as $route => $label) {
            $this->assertStringContainsString(route($route), $group);
            $this->assertStringContainsString($label, $group);
        }
    }

    public function test_no_second_examinations_menu_and_no_collapsible_sidebar(): void
    {
        $college = $this->makeCollege('RNAVB');
        $user = $this->makeUserWithPermissions($college, [
            'results.view', 'result_calculation.view', 'grade_scales.view', 'result_publishing.view',
        ]);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        // A single <aside> sidebar and a single Examinations heading.
        $this->assertSame(1, substr_count($html, '<aside'), 'The layout must keep one sidebar.');
        $this->assertSame(1, substr_count($html, '>Examinations</div>'));

        // The group is a flat list of nav-links: no nested submenu container.
        $this->assertStringNotContainsString('Examinations Submenu', $html);
        $this->assertStringNotContainsString('data-collapse', $html);
    }
}
