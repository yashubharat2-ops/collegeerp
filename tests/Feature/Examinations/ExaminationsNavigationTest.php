<?php

namespace Tests\Feature\Examinations;

use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

/**
 * Regression suite for the sidebar navigation.
 *
 * Guards that Phase 2 (Exam Attendance, Marks Entry), Phase 3 (Results,
 * Result Calculation, Grade / Pass-Fail, Result Publishing) and Phase 4
 * (Marksheets, Grade Cards, Exam Reports, Student Result History) are rendered
 * inside the single existing Examinations section, gated on their respective
 * .view permissions, without duplicating Examinations / Exam Schedule and
 * without introducing any other future items.
 */
class ExaminationsNavigationTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    /** Every .view permission that unlocks an Examinations sidebar entry. */
    private const ALL_EXAM_VIEW_PERMISSIONS = [
        'examinations.view',
        'exam_schedules.view',
        'exam_attendance.view',
        'exam_marks.view',
        'results.view',
        'result_calculation.view',
        'grade_scales.view',
        'result_publishing.view',
        'marksheets.view',
        'grade_cards.view',
        'exam_reports.view',
        'student_result_history.view',
    ];

    /** The twelve labels the single Examinations group must contain. */
    private const EXPECTED_NAV_LINKS = [
        'examinations.index' => 'Examinations',
        'exam-schedules.index' => 'Exam Schedule',
        'exam-attendance.index' => 'Exam Attendance',
        'exam-marks.index' => 'Marks Entry',
        'results.index' => 'Results',
        'result-calculation.index' => 'Result Calculation',
        'grade-scales.index' => 'Grade / Pass-Fail',
        'result-publishing.index' => 'Result Publishing',
        'marksheets.index' => 'Marksheets',
        'grade-cards.index' => 'Grade Cards',
        'exam-reports.index' => 'Exam Reports',
        'student-result-history.index' => 'Student Result History',
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

    public function test_examinations_group_lists_all_twelve_items_under_one_section(): void
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
        $this->assertSame(12, substr_count($group, 'class="nav-link"'),
            'The Examinations group must contain exactly 12 entries.');

        foreach (self::EXPECTED_NAV_LINKS as $route => $label) {
            $url = route($route);
            $this->assertStringContainsString($url, $group, "Missing link to {$url}.");
            $this->assertStringContainsString($label, $group, "Missing label {$label}.");
        }

        // No duplicated entries and no Phase 4 items.
        foreach (self::EXPECTED_NAV_LINKS as $label) {
            $this->assertSame(1, substr_count($group, $label), "Duplicated sidebar entry: {$label}");
        }

        foreach (['Certificates', 'Ranking', 'Merit List'] as $future) {
            $this->assertStringNotContainsString($future, $group, "Phase 4 item must not appear: {$future}");
        }
    }

    public function test_phase_two_links_respect_rbac_permissions(): void
    {
        $college = $this->makeCollege('EXNAV2');

        $attendanceOnly = $this->makeUserWithPermissions($college, ['exam_attendance.view']);
        $this->asCollege($college, $attendanceOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('exam-attendance.index'), false)
            ->assertSee('Exam Attendance')
            ->assertDontSee(route('exam-marks.index'), false)
            ->assertDontSee('Marks Entry')
            ->assertDontSee(route('results.index'), false)
            ->assertDontSee(route('result-calculation.index'), false)
            ->assertDontSee(route('grade-scales.index'), false)
            ->assertDontSee(route('result-publishing.index'), false)
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
            ->assertDontSee(route('results.index'), false)
            ->assertDontSee(route('result-calculation.index'), false)
            ->assertDontSee(route('grade-scales.index'), false)
            ->assertDontSee(route('result-publishing.index'), false)
            ->assertDontSee(route('exam-schedules.index'), false)
            ->assertDontSee(route('examinations.index'), false);
    }

    public function test_examinations_section_is_hidden_without_examination_permissions(): void
    {
        $college = $this->makeCollege('EXNAV3');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Examinations</div>', false)
            ->assertDontSee('Exam Attendance')
            ->assertDontSee('Marks Entry');
    }

    public function test_super_admin_sees_the_full_examinations_group(): void
    {
        $college = $this->makeCollege('EXNAV4');
        $super = $this->makeSuperAdmin($college);

        $response = $this->asCollege($college, $super)->get(route('dashboard'))->assertOk();
        $group = $this->examinationsNavGroup($response->getContent());

        $this->assertSame(12, substr_count($group, 'class="nav-link"'),
            'Super Admin must see the complete Examinations group.');

        foreach (self::EXPECTED_NAV_LINKS as $route => $label) {
            $this->assertStringContainsString(route($route), $group);
            $this->assertStringContainsString($label, $group);
        }
    }
}
