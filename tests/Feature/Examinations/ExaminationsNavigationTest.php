<?php

namespace Tests\Feature\Examinations;

use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

/**
 * Regression suite for the sidebar navigation.
 *
 * Guards that Exam Attendance and Marks Entry (Examinations Phase 2) are
 * rendered inside the single existing Examinations section, gated on their
 * respective .view permissions, without duplicating Examinations / Exam
 * Schedule or introducing Phase 3 items.
 */
class ExaminationsNavigationTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private const ALL_EXAM_VIEW_PERMISSIONS = [
        'examinations.view',
        'exam_schedules.view',
        'exam_attendance.view',
        'exam_marks.view',
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

    public function test_examinations_group_lists_all_four_items_under_one_section(): void
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
        $this->assertSame(4, substr_count($group, 'class="nav-link"'),
            'The Examinations group must contain exactly 4 entries.');

        foreach ([
            route('examinations.index') => 'Examinations',
            route('exam-schedules.index') => 'Exam Schedule',
            route('exam-attendance.index') => 'Exam Attendance',
            route('exam-marks.index') => 'Marks Entry',
        ] as $url => $label) {
            $this->assertStringContainsString($url, $group, "Missing link to {$url}.");
            $this->assertStringContainsString($label, $group, "Missing label {$label}.");
        }

        // No duplicated entries and no Phase 3 items.
        $this->assertSame(1, substr_count($group, 'Exam Schedule'));
        $this->assertSame(1, substr_count($group, 'Exam Attendance'));
        $this->assertSame(1, substr_count($group, 'Marks Entry'));
        $this->assertStringNotContainsString('Results', $group);
        $this->assertStringNotContainsString('Marksheet', $group);
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

        $this->assertSame(4, substr_count($group, 'class="nav-link"'),
            'Super Admin must see the complete Examinations group.');
        $this->assertStringContainsString(route('exam-attendance.index'), $group);
        $this->assertStringContainsString(route('exam-marks.index'), $group);
    }
}
