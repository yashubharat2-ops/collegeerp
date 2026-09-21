<?php

namespace Tests\Feature\StudentResultHistory;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase4\Phase4TestHelpers;
use Tests\TestCase;

/**
 * Student Result History — a student's published examination timeline across
 * academic years and terms (Examinations Phase 4).
 *
 * The timeline is derived live from the student's published ExamResult rows;
 * nothing is duplicated into a history table. This suite covers the student
 * picker, the chronological timeline, the published-only rule, tenant
 * isolation, RBAC, deterministic pagination, navigation gating and permission
 * seeding.
 *
 * NOTE on negative assertions: the picker's filter dropdowns legitimately
 * contain year / program names, so absence is asserted against the student
 * table body (see `resultRows()`), never against the full page.
 */
class StudentResultHistoryTest extends TestCase
{
    use Phase4TestHelpers;

    private const VIEW = ['student_result_history.view'];

    /**
     * One student with two enrollments and two published results: an older
     * academic year (passing) and a newer one (failing).
     *
     * The newer year is created FIRST, so examination-id order is the reverse
     * of chronological order — any test asserting oldest-first genuinely
     * exercises the timeline sort.
     *
     * @return array<string, mixed>
     */
    private function historyFixture(string $prefix): array
    {
        $college = $this->makeCollege($prefix);
        $ctxNew = $this->makeExamContextWithSubjects($college, $prefix.'N', 2);
        $ctxOld = $this->makeExamContextWithSubjects($college, $prefix.'O', 2);
        $ctxOld['year']->update(['starts_on' => '2025-08-01', 'ends_on' => '2026-05-31']);
        $scale = $this->makeGradeScale($college);

        [$student, $enrollmentOld] = $this->makeEnrolledStudent($college, $ctxOld, $prefix.'O');
        [, $enrollmentNew] = $this->makeEnrolledStudent($college, $ctxNew, $prefix.'N');
        $enrollmentNew->update(['student_id' => $student->id]);

        $this->recordMark($ctxOld['schedules'][0], $enrollmentOld, 80.0);
        $this->recordMark($ctxOld['schedules'][1], $enrollmentOld, 70.0);
        $this->recordMark($ctxNew['schedules'][0], $enrollmentNew, 40.0);
        $this->recordMark($ctxNew['schedules'][1], $enrollmentNew, 20.0);

        $this->calculateAndPublish($college, $ctxOld['exam']->id, $scale->id);
        $this->calculateAndPublish($college, $ctxNew['exam']->id, $scale->id);

        $statuses = $this->withTenant($college, function () use ($ctxOld, $ctxNew, $enrollmentOld, $enrollmentNew) {
            return [
                $this->resultFor($ctxOld['exam'], $enrollmentOld)->result_status,
                $this->resultFor($ctxNew['exam'], $enrollmentNew)->result_status,
            ];
        });

        $this->assertSame(['pass', 'fail'], $statuses);

        return compact('college', 'ctxNew', 'ctxOld', 'scale', 'student', 'enrollmentOld', 'enrollmentNew');
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

    // ------------------------------------------------------------------- index

    public function test_authorized_user_can_view_the_student_picker(): void
    {
        $f = $this->historyFixture('HSA');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $rows = $this->resultRows(
            $this->asCollege($f['college'], $user)
                ->get(route('student-result-history.index'))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString($f['student']->student_number, $rows);
        $this->assertStringContainsString($f['student']->fullName(), $rows);
        // The current enrollment is the oldest active one (the older year).
        $this->assertStringContainsString($f['enrollmentOld']->enrollment_number, $rows);
        $this->assertStringContainsString($f['ctxOld']['year']->name, $rows);
        $this->assertStringContainsString($f['ctxOld']['prog']->name, $rows);
        $this->assertStringContainsString('/student-result-history/'.$f['student']->id, $rows);
        $this->assertStringContainsString('View history', $rows);
    }

    public function test_history_listing_requires_the_view_permission(): void
    {
        $f = $this->historyFixture('HSB');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('student-result-history.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_the_student_picker(): void
    {
        $f = $this->historyFixture('HSC');

        $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('student-result-history.index'))
            ->assertOk()
            ->assertSee($f['student']->student_number);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('student-result-history.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- timeline

    public function test_authorized_user_can_view_a_students_published_timeline(): void
    {
        $f = $this->historyFixture('HSD');

        $stored = $this->withTenant($f['college'], function () use ($f) {
            $old = $this->resultFor($f['ctxOld']['exam'], $f['enrollmentOld'])->fresh();
            $new = $this->resultFor($f['ctxNew']['exam'], $f['enrollmentNew'])->fresh();

            return [
                'old' => [
                    'totals' => $old->total_obtained_marks.' / '.$old->total_max_marks,
                    'percentage' => $old->percentage.'%',
                    'grade' => $old->overall_grade,
                    'published' => $old->published_at->format('M d, Y'),
                ],
                'new' => [
                    'totals' => $new->total_obtained_marks.' / '.$new->total_max_marks,
                    'percentage' => $new->percentage.'%',
                    'grade' => $new->overall_grade,
                    'published' => $new->published_at->format('M d, Y'),
                ],
                'subjects' => [
                    $f['ctxOld']['subjects'][0]->name,
                    $f['ctxOld']['subjects'][1]->name,
                    $f['ctxNew']['subjects'][0]->name,
                    $f['ctxNew']['subjects'][1]->name,
                ],
            ];
        });

        $response = $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk()
            // Student header
            ->assertSee($f['student']->fullName())
            ->assertSee($f['student']->student_number)
            // Older year: pass
            ->assertSee($f['ctxOld']['exam']->name)
            ->assertSee($f['ctxOld']['year']->name)
            ->assertSee($f['ctxOld']['term']->name)
            ->assertSee($f['ctxOld']['prog']->name)
            ->assertSee($f['ctxOld']['sec']->name)
            ->assertSee($f['enrollmentOld']->enrollment_number)
            ->assertSee($stored['old']['totals'])
            ->assertSee($stored['old']['percentage'])
            ->assertSee($stored['old']['grade'])
            ->assertSee($stored['old']['published'])
            // Newer year: fail
            ->assertSee($f['ctxNew']['exam']->name)
            ->assertSee($f['ctxNew']['year']->name)
            ->assertSee($f['enrollmentNew']->enrollment_number)
            ->assertSee($stored['new']['totals'])
            ->assertSee($stored['new']['grade'])
            ->assertSee($stored['new']['published'])
            ->assertSee('Pass')
            ->assertSee('Fail');

        foreach ($stored['subjects'] as $subject) {
            $response->assertSee($subject);
        }
    }

    public function test_timeline_is_ordered_oldest_first(): void
    {
        $f = $this->historyFixture('HSE');

        // Sanity: the newer examination really does have the lower id.
        $this->assertLessThan($f['ctxOld']['exam']->id, $f['ctxNew']['exam']->id);

        $html = $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk()
            ->getContent();

        // Order is asserted on the timeline card headlines: a raw strpos would
        // also match the publish flash banner rendered above the cards.
        preg_match_all('/<h3[^>]*>(.*?)<\\/h3>/s', $html, $matches);
        $headlines = array_values(array_filter(
            $matches[1],
            fn (string $headline): bool => str_contains($headline, 'Exam ')
        ));

        $this->assertSame(
            [$f['ctxOld']['exam']->name, $f['ctxNew']['exam']->name],
            $headlines,
            'The older examination must render before the newer one.'
        );
    }

    public function test_student_without_published_results_gets_an_empty_timeline(): void
    {
        $f = $this->publishedFixture('HSF');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        // A second, result-less student in the same college.
        [$plain] = $this->makeEnrolledStudent($f['college'], $f['ctx'], 'HSF2');

        $this->asCollege($f['college'], $user)
            ->get(route('student-result-history.show', $plain))
            ->assertOk()
            ->assertSee('This student has no published results yet.')
            ->assertDontSee($f['ctx']['exam']->name);
    }

    public function test_timeline_requires_the_view_permission(): void
    {
        $f = $this->historyFixture('HSG');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('student-result-history.show', $f['student']))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_a_students_timeline(): void
    {
        $f = $this->historyFixture('HSH');

        $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk()
            ->assertSee($f['ctxOld']['exam']->name)
            ->assertSee($f['ctxNew']['exam']->name);
    }

    // ------------------------------------------------- published-only enforcement

    public function test_unpublished_results_never_appear_in_a_timeline(): void
    {
        $f = $this->historyFixture('HSI');

        // A third, calculated-but-unpublished examination for the same student.
        $ctx = $this->makeExamContextWithSubjects($f['college'], 'HSI3', 2);
        $ctx['year']->update(['starts_on' => '2028-08-01', 'ends_on' => '2029-05-31']);
        [, $enrollment] = $this->makeEnrolledStudent($f['college'], $ctx, 'HSI3');
        $enrollment->update(['student_id' => $f['student']->id]);
        $this->recordMark($ctx['schedules'][0], $enrollment, 90.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 90.0);

        $calculator = $this->makeUserWithPermissions($f['college'], ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($f['college'], $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $f['scale']->id,
            ])
            ->assertRedirect();

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk()
            ->assertSee($f['ctxOld']['exam']->name)
            ->assertSee($f['ctxNew']['exam']->name)
            ->assertDontSee($ctx['exam']->name);
    }

    public function test_soft_deleted_results_disappear_from_the_timeline(): void
    {
        $f = $this->historyFixture('HSJ');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk()
            ->assertSee($f['ctxNew']['exam']->name);

        $this->withTenant($f['college'], function () use ($f): void {
            $this->resultFor($f['ctxNew']['exam'], $f['enrollmentNew'])->delete();
        });

        $this->asCollege($f['college'], $user)
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk()
            ->assertSee($f['ctxOld']['exam']->name)
            ->assertDontSee($f['ctxNew']['exam']->name);
    }

    public function test_soft_deleted_students_have_no_timeline(): void
    {
        $f = $this->historyFixture('HSK');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $user)
            ->get(route('student-result-history.show', $f['student']))
            ->assertOk();

        $this->withTenant($f['college'], fn () => $f['student']->delete());

        $this->asCollege($f['college'], $user)
            ->get(route('student-result-history.show', $f['student']))
            ->assertNotFound();

        $rows = $this->resultRows(
            $this->asCollege($f['college'], $user)
                ->get(route('student-result-history.index'))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringNotContainsString($f['student']->student_number, $rows);
    }

    // ---------------------------------------------------------- tenant isolation

    public function test_students_of_another_college_are_never_listed(): void
    {
        $f = $this->historyFixture('HSL');
        $other = $this->makeCollege('HSM');

        $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW))
            ->get(route('student-result-history.index'))
            ->assertOk()
            ->assertSee('No students found for the selected filters.')
            ->assertDontSee($f['student']->student_number);
    }

    public function test_a_timeline_from_another_college_cannot_be_opened(): void
    {
        $f = $this->historyFixture('HSN');
        $other = $this->makeCollege('HSO');
        $intruder = $this->makeUserWithPermissions($other, self::VIEW);

        $this->asCollege($other, $intruder)
            ->get(route('student-result-history.show', $f['student']))
            ->assertNotFound();

        $this->asCollege($other, $intruder)->get('/student-result-history/'.$f['student']->id)->assertNotFound();
        $this->asCollege($other, $intruder)->get('/student-result-history/'.($f['student']->id + 100000))->assertNotFound();
        $this->asCollege($other, $intruder)->get('/student-result-history/not-an-id')->assertNotFound();

        $owner = $this->makeUserWithPermissions($f['college'], self::VIEW);
        $this->asCollege($f['college'], $owner)
            ->get('/student-result-history/'.$f['student']->id)
            ->assertOk()
            ->assertSee($f['student']->fullName());
    }

    // ------------------------------------------------------------------ filters

    public function test_picker_can_be_searched_by_number_and_name(): void
    {
        $f = $this->historyFixture('HSP');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        foreach ([$f['student']->student_number, $f['student']->last_name] as $term) {
            $rows = $this->resultRows(
                $this->asCollege($f['college'], $user)
                    ->get(route('student-result-history.index', ['search' => $term]))
                    ->assertOk()
                    ->getContent()
            );

            $this->assertStringContainsString($f['student']->student_number, $rows);
        }

        $rows = $this->resultRows(
            $this->asCollege($f['college'], $user)
                ->get(route('student-result-history.index', ['search' => 'no-such-student-xyz']))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringNotContainsString($f['student']->student_number, $rows);
    }

    public function test_picker_can_be_filtered_by_academic_year_and_program(): void
    {
        $f = $this->historyFixture('HSQ');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);

        // A second student enrolled only in the newer year / program.
        [$newcomer] = $this->makeEnrolledStudent($f['college'], $f['ctxNew'], 'HSQ2');

        // The older year matches only the history student.
        $rows = $this->resultRows(
            $this->asCollege($f['college'], $user)
                ->get(route('student-result-history.index', ['academic_year_id' => $f['ctxOld']['year']->id]))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString($f['student']->student_number, $rows);
        $this->assertStringNotContainsString($newcomer->student_number, $rows);

        // The older program matches only the history student.
        $rows = $this->resultRows(
            $this->asCollege($f['college'], $user)
                ->get(route('student-result-history.index', ['program_id' => $f['ctxOld']['prog']->id]))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString($f['student']->student_number, $rows);
        $this->assertStringNotContainsString($newcomer->student_number, $rows);

        // The newer program matches both.
        $rows = $this->resultRows(
            $this->asCollege($f['college'], $user)
                ->get(route('student-result-history.index', ['program_id' => $f['ctxNew']['prog']->id]))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString($f['student']->student_number, $rows);
        $this->assertStringContainsString($newcomer->student_number, $rows);

        // A forged foreign-college program id matches nothing.
        $foreignProgram = $this->makeExamContextWithSubjects($this->makeCollege('HSR'), 'HSR', 1)['prog'];

        $this->asCollege($f['college'], $user)
            ->get(route('student-result-history.index', ['program_id' => $foreignProgram->id]))
            ->assertOk()
            ->assertSee('No students found for the selected filters.');
    }

    public function test_reading_history_never_writes_marks_or_results(): void
    {
        $f = $this->historyFixture('HSS');

        $resultsBefore = DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all();
        $itemsBefore = DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all();

        $user = $this->makeUserWithPermissions($f['college'], self::VIEW);
        $this->asCollege($f['college'], $user)->get(route('student-result-history.index'))->assertOk();
        $this->asCollege($f['college'], $user)->get(route('student-result-history.show', $f['student']))->assertOk();

        $this->assertSame($resultsBefore, DB::table('exam_results')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($itemsBefore, DB::table('exam_result_items')->get()->map(fn ($row) => (array) $row)->all());
    }

    // --------------------------------------------------------------- pagination

    public function test_student_picker_pagination_is_deterministic(): void
    {
        $college = $this->makeCollege('HST');
        $ctx = $this->makeExamContextWithSubjects($college, 'HST', 1);

        // Single-letter suffixes sort in creation order.
        foreach (range('A', 'P') as $letter) {
            $this->makeEnrolledStudent($college, $ctx, 'HST'.$letter);
        }

        $user = $this->makeUserWithPermissions($college, self::VIEW);

        $pageOne = $this->asCollege($college, $user)->get(route('student-result-history.index'))->assertOk();
        $pageTwo = $this->asCollege($college, $user)->get(route('student-result-history.index', ['page' => 2]))->assertOk();

        $this->assertSame(15, substr_count($pageOne->getContent(), 'View history'));
        $this->assertSame(1, substr_count($pageTwo->getContent(), 'View history'));
        $this->assertStringContainsString('HSTP', $pageTwo->getContent());
        $this->assertStringNotContainsString('HSTP', $pageOne->getContent());
    }

    // --------------------------------------------------------------- navigation

    public function test_navigation_shows_student_result_history_with_the_view_permission(): void
    {
        $college = $this->makeCollege('HSU');
        $user = $this->makeUserWithPermissions($college, self::VIEW);

        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('student-result-history.index'), $html);
        $this->assertStringContainsString('Student Result History', $html);
    }

    public function test_navigation_hides_student_result_history_without_the_view_permission(): void
    {
        $college = $this->makeCollege('HSV');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('student-result-history.index'), false)
            ->assertDontSee('Student Result History');
    }

    // ------------------------------------------------------------------- seeder

    public function test_student_result_history_permission_seeding_is_idempotent(): void
    {
        $this->assertSame(1, Permission::query()->where('slug', 'student_result_history.view')->count());

        $this->seed();
        $this->seed();

        $this->assertSame(1, Permission::query()->where('slug', 'student_result_history.view')->count());

        $permission = Permission::query()->where('slug', 'student_result_history.view')->firstOrFail();
        $this->assertSame('student_result_history', $permission->module);
        $this->assertSame('view', $permission->action);
        $this->assertTrue($permission->is_active);
    }
}
