<?php

namespace Tests\Feature\ExamReports;

use App\Models\ExamMark;
use App\Models\ExamSchedule;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase4\Phase4TestHelpers;
use Tests\TestCase;

/**
 * Exam Reports — read-only published-result summaries (Examinations Phase 4).
 *
 * Every figure is a simple count over published ExamResult / ExamResultItem
 * rows, grouped by examination, program, subject and result status. This suite
 * covers authorized access, RBAC, the published-only rule, tenant isolation
 * (including forged filter ids), summary correctness, filtering, navigation
 * gating and permission seeding.
 *
 * Count assertions target exact rendered markup (`stat-value` cards and
 * per-row table cells) so bare numbers can never match unrelated content.
 */
class ExamReportsTest extends TestCase
{
    use Phase4TestHelpers;

    private const VIEW = ['exam_reports.view'];

    /**
     * One college, one examination shared by two programs (with the same two
     * subjects scheduled per program section): student 1 passes in program 1,
     * student 2 fails in program 2. Everything calculated + published.
     *
     * @return array<string, mixed>
     */
    private function twoProgramFixture(string $prefix): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        $scale = $this->makeGradeScale($college);

        // A second program + section sharing the examination and its subjects:
        // names sort before program 1 to prove the name-ordered report.
        $program2 = Program::create([
            'college_id' => $college->id,
            'name' => "AAA Program {$prefix}",
            'code' => "AP-{$prefix}",
            'status' => 'active',
        ]);
        $section2 = Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $program2->id,
            'name' => "Sec2 {$prefix}",
            'code' => "S2-{$prefix}",
            'status' => 'active',
        ]);
        $schedules2 = [];
        foreach ($ctx['subjects'] as $index => $subject) {
            $schedules2[] = ExamSchedule::create([
                'college_id' => $college->id,
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $program2->id,
                'section_id' => $section2->id,
                'subject_id' => $subject->id,
                'exam_date' => '2026-10-1'.($index + 1),
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => ExamSchedule::STATUS_SCHEDULED,
            ]);
        }

        [$student1, $enrollment1] = $this->makeEnrolledStudent($college, $ctx, $prefix.'A');
        $this->recordMark($ctx['schedules'][0], $enrollment1, 80.0);
        $this->recordMark($ctx['schedules'][1], $enrollment1, 70.0);

        [$student2, $enrollment2] = $this->makeEnrolledStudent(
            $college,
            array_merge($ctx, ['sec' => $section2, 'prog' => $program2]),
            $prefix.'B'
        );
        $this->recordMark($schedules2[0], $enrollment2, 40.0);
        $this->recordMark($schedules2[1], $enrollment2, 20.0);

        // Each section is calculated separately: an enrollment only sits its
        // own section's papers, so a whole-exam run would leave both results
        // incomplete (and therefore unpublishable).
        $calculator = $this->makeUserWithPermissions($college, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        foreach ([$ctx['sec']->id, $section2->id] as $sectionId) {
            $this->asCollege($college, $calculator)
                ->post(route('result-calculation.calculate'), [
                    'examination_id' => $ctx['exam']->id,
                    'grade_scale_id' => $scale->id,
                    'section_id' => $sectionId,
                ])
                ->assertRedirect();
        }

        $publisher = $this->makeUserWithPermissions($college, ['results.view', 'result_publishing.view', 'result_publishing.publish']);
        $this->asCollege($college, $publisher)
            ->post(route('result-publishing.examination', $ctx['exam']->id))
            ->assertRedirect();

        $statuses = $this->withTenant($college, fn () => \App\Models\ExamResult::query()
            ->where('examination_id', $ctx['exam']->id)
            ->pluck('result_status')
            ->sort()
            ->values()
            ->all());

        $this->assertSame(['fail', 'pass'], $statuses);

        return compact('college', 'ctx', 'scale', 'program2', 'section2', 'student1', 'enrollment1', 'student2', 'enrollment2');
    }

    private function calculateAndPublish(\App\Models\College $college, int $examinationId, int $scaleId): void
    {
        $calculator = $this->makeUserWithPermissions($college, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $examinationId,
                'grade_scale_id' => $scaleId,
            ])
            ->assertRedirect();

        $publisher = $this->makeUserWithPermissions($college, ['results.view', 'result_publishing.view', 'result_publishing.publish']);
        $this->asCollege($college, $publisher)
            ->post(route('result-publishing.examination', $examinationId))
            ->assertRedirect();
    }

    /**
     * @return array<string, mixed>
     */
    private function statusMixFixture(string $prefix): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        $scale = $this->makeGradeScale($college);

        [, $passing] = $this->makeEnrolledStudent($college, $ctx, $prefix.'P');
        $this->recordMark($ctx['schedules'][0], $passing, 80.0);
        $this->recordMark($ctx['schedules'][1], $passing, 70.0);

        [, $failing] = $this->makeEnrolledStudent($college, $ctx, $prefix.'F');
        $this->recordMark($ctx['schedules'][0], $failing, 40.0);
        $this->recordMark($ctx['schedules'][1], $failing, 20.0);

        [, $absent] = $this->makeEnrolledStudent($college, $ctx, $prefix.'A');
        $this->recordMark($ctx['schedules'][0], $absent, 75.0);
        $this->recordMark($ctx['schedules'][1], $absent, null, ExamMark::STATUS_ABSENT);

        $this->calculateAndPublish($college, $ctx['exam']->id, $scale->id);

        $statuses = $this->withTenant($college, fn () => \App\Models\ExamResult::query()
            ->where('examination_id', $ctx['exam']->id)
            ->pluck('result_status')
            ->sort()
            ->values()
            ->all());

        $this->assertSame(['absent', 'fail', 'pass'], $statuses);

        return compact('college', 'ctx', 'scale');
    }

    // ------------------------------------------------------------------ access

    public function test_authorized_user_can_view_exam_reports(): void
    {
        $f = $this->publishedFixture('RPA');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->assertSee($f['ctx']['exam']->name)
            ->assertSee('Published Results')
            ->assertSee($f['ctx']['prog']->name)
            ->assertSee($f['ctx']['subjects'][0]->name)
            ->assertSee($f['ctx']['subjects'][1]->name);
    }

    public function test_exam_reports_require_the_view_permission(): void
    {
        $f = $this->publishedFixture('RPB');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('exam-reports.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_exam_reports(): void
    {
        $f = $this->publishedFixture('RPC');

        $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->assertSee('Published Results');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('exam-reports.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- summaries

    public function test_status_summary_counts_match_the_published_results(): void
    {
        $f = $this->statusMixFixture('RPD');

        $html = $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->getContent();

        // One pass, one fail, one absent — and nothing else: 33.3% pass rate.
        $this->assertSame(1, substr_count($html, '<p class="stat-value">3</p>'));
        $this->assertSame(3, substr_count($html, '<p class="stat-value">1</p>'));
        $this->assertSame(2, substr_count($html, '<p class="stat-value">0</p>'));
        $this->assertStringContainsString('<p class="stat-value">33.3%</p>', $html);
    }

    public function test_program_summary_groups_counts_by_program_in_name_order(): void
    {
        $f = $this->twoProgramFixture('RPE');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $html = $this->asCollege($f['college'], $user)
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->getContent();

        $bodies = $this->tableBodies($html);
        $this->assertCount(2, $bodies, 'The report must render a program table and a subject table.');

        // Deterministic order: AAA Program sorts before Prog RPE even though
        // it was created second.
        $this->assertLessThan(strpos($bodies[0], $f['ctx']['prog']->name), strpos($bodies[0], 'AAA Program'));

        // AAA Program: one result, zero passes (total 1, fail 1).
        $failing = $this->rowFor($bodies[0], 'AAA Program');
        $this->assertSame(4, substr_count($failing, '<td class="text-right">0</td>'));
        $this->assertStringContainsString('>0%<', $failing);

        $passing = $this->rowFor($bodies[0], $f['ctx']['prog']->name);
        $this->assertSame(2, substr_count($passing, '<td class="text-right">1</td>'));
        $this->assertStringContainsString('>100%<', $passing);

        // Overall: two results, one pass — 50%.
        $this->assertStringContainsString('<p class="stat-value">2</p>', $html);
        $this->assertStringContainsString('<p class="stat-value">50%</p>', $html);
    }

    public function test_subject_summary_groups_item_counts_by_subject(): void
    {
        $f = $this->twoProgramFixture('RPF');

        $html = $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->getContent();

        $bodies = $this->tableBodies($html);
        $this->assertCount(2, $bodies);

        // Subject 1: 80/pass and 40/pass → two of two passed.
        $first = $this->rowFor($bodies[1], $f['ctx']['subjects'][0]->name);
        $this->assertStringContainsString('<td class="text-right">100.00</td>', $first);
        $this->assertSame(2, substr_count($first, '<td class="text-right">2</td>'));
        $this->assertStringContainsString('>100%<', $first);

        // Subject 2: 70/pass and 20/fail → one of two passed.
        $second = $this->rowFor($bodies[1], $f['ctx']['subjects'][1]->name);
        $this->assertSame(1, substr_count($second, '<td class="text-right">2</td>'));
        $this->assertStringContainsString('>50%<', $second);
    }

    // ------------------------------------------------------------------ filters

    public function test_report_defaults_to_the_latest_examination_with_published_results(): void
    {
        $college = $this->makeCollege('RPG');
        $first = $this->makeExamContextWithSubjects($college, 'RPG1', 2);
        $second = $this->makeExamContextWithSubjects($college, 'RPG2', 2);
        $scale = $this->makeGradeScale($college);

        [, $enrollment1] = $this->makeEnrolledStudent($college, $first, 'RPG1');
        $this->recordMark($first['schedules'][0], $enrollment1, 80.0);
        $this->recordMark($first['schedules'][1], $enrollment1, 70.0);

        [, $enrollment2] = $this->makeEnrolledStudent($college, $second, 'RPG2');
        $this->recordMark($second['schedules'][0], $enrollment2, 40.0);
        $this->recordMark($second['schedules'][1], $enrollment2, 20.0);

        $this->calculateAndPublish($college, $first['exam']->id, $scale->id);
        $this->calculateAndPublish($college, $second['exam']->id, $scale->id);

        $user = $this->makeUserWithPermissions($college, self::VIEW);

        // Default: the latest examination (the failing result).
        $default = $this->asCollege($college, $user)
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<p class="stat-value">1</p>', $default);
        $this->assertStringContainsString('<p class="stat-value">0%</p>', $default);

        // Explicitly selecting the first examination flips the report.
        $filtered = $this->asCollege($college, $user)
            ->get(route('exam-reports.index', ['examination_id' => $first['exam']->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<p class="stat-value">1</p>', $filtered);
        $this->assertStringContainsString('<p class="stat-value">100%</p>', $filtered);
    }

    public function test_report_can_be_narrowed_by_program(): void
    {
        $f = $this->twoProgramFixture('RPH');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $html = $this->asCollege($f['college'], $user)
            ->get(route('exam-reports.index', ['program_id' => $f['program2']->id]))
            ->assertOk()
            ->getContent();

        // Only the failing program-2 result remains.
        $this->assertStringContainsString('<p class="stat-value">1</p>', $html);
        $this->assertStringContainsString('<p class="stat-value">0%</p>', $html);
        $this->assertStringNotContainsString($f['ctx']['prog']->name, $this->tableBodies($html)[0]);
        $this->assertStringContainsString('AAA Program', $this->tableBodies($html)[0]);
    }

    // ------------------------------------------------- published-only enforcement

    public function test_unpublished_results_are_excluded_from_reports(): void
    {
        $f = $this->calculatedFixture('RPI');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        foreach ([[], ['examination_id' => $f['ctx']['exam']->id]] as $query) {
            $this->asCollege($f['college'], $user)
                ->get(route('exam-reports.index', $query))
                ->assertOk()
                ->assertSee('No published results found for the selected filters.');
        }
    }

    public function test_soft_deleted_results_are_excluded_from_reports(): void
    {
        $f = $this->publishedFixture('RPJ');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->assertSee('<p class="stat-value">1</p>', false);

        $this->withTenant($f['college'], fn () => $f['result']->delete());

        $this->asCollege($f['college'], $user)
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->assertSee('No published results found for the selected filters.');
    }

    // ---------------------------------------------------------- tenant isolation

    public function test_reports_never_leak_across_colleges(): void
    {
        $f = $this->publishedFixture('RPK');
        $other = $this->makeCollege('RPL');

        $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW))
            ->get(route('exam-reports.index'))
            ->assertOk()
            ->assertSee('No examinations exist yet')
            ->assertDontSee($f['ctx']['exam']->name)
            ->assertDontSee($f['ctx']['prog']->name);
    }

    public function test_forged_filter_ids_yield_an_empty_report(): void
    {
        $f = $this->publishedFixture('RPM');
        $foreign = $this->makeExamContextWithSubjects($this->makeCollege('RPN'), 'RPN', 1);

        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        // A foreign examination id matches nothing in the active college.
        $this->asCollege($f['college'], $user)
            ->get(route('exam-reports.index', ['examination_id' => $foreign['exam']->id]))
            ->assertOk()
            ->assertSee('No published results found for the selected filters.')
            ->assertDontSee($foreign['exam']->name);

        // Same for a foreign program id.
        $this->asCollege($f['college'], $user)
            ->get(route('exam-reports.index', ['program_id' => $foreign['prog']->id]))
            ->assertOk()
            ->assertSee('No published results found for the selected filters.')
            ->assertDontSee($foreign['prog']->name);
    }

    public function test_reading_reports_never_writes_marks_or_results(): void
    {
        $f = $this->twoProgramFixture('RPO');

        $resultsBefore = DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all();
        $itemsBefore = DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all();

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('exam-reports.index'))
            ->assertOk();

        $this->assertSame($resultsBefore, DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($itemsBefore, DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all());
    }

    // --------------------------------------------------------------- navigation

    public function test_navigation_shows_exam_reports_with_the_view_permission(): void
    {
        $college = $this->makeCollege('RPP');
        $user = $this->makeUserWithPermissions($college, self::VIEW);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('exam-reports.index'), $html);
        $this->assertStringContainsString('Exam Reports', $html);
    }

    public function test_navigation_hides_exam_reports_without_the_view_permission(): void
    {
        $college = $this->makeCollege('RPQ');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('exam-reports.index'), false)
            ->assertDontSee('Exam Reports');
    }

    // ------------------------------------------------------------------- seeder

    public function test_exam_reports_permission_seeding_is_idempotent(): void
    {
        $this->assertSame(1, Permission::query()->where('slug', 'exam_reports.view')->count());

        $this->seed();
        $this->seed();

        $this->assertSame(1, Permission::query()->where('slug', 'exam_reports.view')->count());

        $permission = Permission::query()->where('slug', 'exam_reports.view')->firstOrFail();
        $this->assertSame('exam_reports', $permission->module);
        $this->assertSame('view', $permission->action);
        $this->assertTrue($permission->is_active);
    }
}
