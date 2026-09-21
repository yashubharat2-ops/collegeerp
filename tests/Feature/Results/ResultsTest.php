<?php

namespace Tests\Feature\Results;

use App\Models\ExamMark;
use App\Models\ExamResult;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Results — the read-only result display layer (Examinations Phase 3).
 *
 * Covers the list, the detail screen, filtering, deterministic pagination,
 * tenant isolation, published/unpublished visibility and RBAC.
 */
class ResultsTest extends TestCase
{
    use ResultTestHelpers;

    private const VIEW = ['results.view'];

    private const VIEW_UNPUBLISHED = ['results.view', 'results.view_unpublished'];

    /**
     * @param  array<int, float>  $marks
     * @return array<string, mixed>
     */
    private function calculated(string $prefix, array $marks = [80.0, 70.0], ?string $status = null): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix);
        $scale = $this->makeGradeScale($college);

        $this->recordMark($ctx['schedules'][0], $enrollment, $marks[0]);
        $this->recordMark($ctx['schedules'][1], $enrollment, $marks[1]);

        if ($status !== null) {
            $this->withTenant($college, function () use ($ctx, $enrollment, $status): void {
                ExamMark::query()
                    ->where('exam_schedule_id', $ctx['schedules'][1]->id)
                    ->where('student_enrollment_id', $enrollment->id)
                    ->firstOrFail()
                    ->update(['status' => $status, 'obtained_marks' => null]);
            });
        }

        $calculator = $this->makeUserWithPermissions($college, [
            'results.view', 'result_calculation.view', 'result_calculation.calculate',
        ]);

        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        return compact('college', 'ctx', 'student', 'enrollment', 'scale', 'result');
    }

    /** @param  array<string, mixed>  $f */
    private function publish(array $f): void
    {
        $user = $this->makeUserWithPermissions($f['college'], [
            'results.view', 'result_publishing.view', 'result_publishing.publish',
        ]);

        $this->asCollege($f['college'], $user)
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();
    }

    // ------------------------------------------------------------------- index

    public function test_results_index_lists_calculated_results(): void
    {
        $f = $this->calculated('RSA');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number)
            ->assertSee($f['student']->full_name)
            ->assertSee($f['ctx']['exam']->name);
    }

    public function test_results_index_shows_the_three_independent_status_columns(): void
    {
        $f = $this->calculated('RSB');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index'))
            ->assertOk()
            ->assertSee('Pass')
            ->assertSee('Calculated')
            ->assertSee('Unpublished');
    }

    public function test_results_index_requires_the_view_permission(): void
    {
        $f = $this->calculated('RSC');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('results.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_the_results_index(): void
    {
        $f = $this->calculated('RSD');

        $this->asCollege($f['college'], $this->makeSuperAdmin($f['college']))
            ->get(route('results.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    // ------------------------------------------------- published / unpublished

    public function test_unpublished_results_are_hidden_without_the_unpublished_permission(): void
    {
        $f = $this->calculated('RSE');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('results.index'))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED))
            ->get(route('results.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    public function test_published_results_are_visible_without_the_unpublished_permission(): void
    {
        $f = $this->calculated('RSF');
        $this->publish($f);

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('results.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number)
            ->assertSee('Published');
    }

    public function test_an_unpublished_result_is_never_rendered_as_published(): void
    {
        $f = $this->calculated('RSG');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.show', $f['result']))
            ->assertOk()
            ->assertSee('not published')
            ->assertSee('Unpublished');
    }

    // ------------------------------------------------------------------- detail

    public function test_result_detail_shows_student_examination_and_subject_rows(): void
    {
        $f = $this->calculated('RSH');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $response = $this->asCollege($f['college'], $user)->get(route('results.show', $f['result']))->assertOk();

        // Student
        $response->assertSee($f['student']->full_name)
            ->assertSee($f['student']->student_number)
            ->assertSee($f['enrollment']->enrollment_number)
            ->assertSee($f['ctx']['prog']->name)
            ->assertSee($f['ctx']['sec']->name)
            ->assertSee($f['ctx']['year']->name);

        // Examination + term
        $response->assertSee($f['ctx']['exam']->name)->assertSee($f['ctx']['term']->name);

        // Subject rows
        $response->assertSee($f['ctx']['subjects'][0]->name)->assertSee($f['ctx']['subjects'][1]->name);

        // Overall
        $response->assertSee('200.00')->assertSee('150.00')->assertSee('75.000%')->assertSee('Pass');
    }

    public function test_result_detail_requires_the_view_permission(): void
    {
        $f = $this->calculated('RSI');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('results.show', $f['result']))
            ->assertForbidden();
    }

    public function test_an_unpublished_result_detail_requires_the_unpublished_permission(): void
    {
        $f = $this->calculated('RSJ');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('results.show', $f['result']))
            ->assertForbidden();

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED))
            ->get(route('results.show', $f['result']))
            ->assertOk();
    }

    public function test_a_published_result_detail_is_visible_without_the_unpublished_permission(): void
    {
        $f = $this->calculated('RSK');
        $this->publish($f);

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('results.show', $f['result']))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    // ------------------------------------------------------------------ filters

    public function test_results_can_be_filtered_by_examination(): void
    {
        $f = $this->calculated('RSL');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $otherExam = $this->makeExamContextWithSubjects($f['college'], 'RSL2', 1)['exam'];

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', ['examination_id' => $otherExam->id]))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', ['examination_id' => $f['ctx']['exam']->id]))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    public function test_results_can_be_filtered_by_result_status(): void
    {
        $passing = $this->calculated('RSM');
        $failing = $this->calculated('RSN', [40.0, 20.0]);

        $user = $this->makeUserWithPermissions($passing['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($passing['college'], $user)
            ->get(route('results.index', ['result_status' => 'fail']))
            ->assertOk()
            ->assertDontSee($passing['enrollment']->enrollment_number);

        $this->asCollege($passing['college'], $user)
            ->get(route('results.index', ['result_status' => 'pass']))
            ->assertOk()
            ->assertSee($passing['enrollment']->enrollment_number);

        // The failing fixture lives in its own college, so it is not listed here.
        $this->assertSame('fail', $failing['result']->result_status);
    }

    public function test_results_can_be_filtered_by_publication_state(): void
    {
        $unpublished = $this->calculated('RSO');
        $published = $this->calculated('RSP');
        $this->publish($published);

        $user = $this->makeUserWithPermissions($unpublished['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($unpublished['college'], $user)
            ->get(route('results.index', ['publication_status' => 'published']))
            ->assertOk()
            ->assertDontSee($unpublished['enrollment']->enrollment_number);

        $this->asCollege($unpublished['college'], $user)
            ->get(route('results.index', ['publication_status' => 'unpublished']))
            ->assertOk()
            ->assertSee($unpublished['enrollment']->enrollment_number);
    }

    public function test_the_unpublished_filter_never_leaks_rows_to_a_viewer_without_the_permission(): void
    {
        $f = $this->calculated('RSQ');
        $viewer = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $viewer)
            ->get(route('results.index', ['publication_status' => 'unpublished']))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);
    }

    public function test_results_can_be_searched_by_student_and_enrollment_number(): void
    {
        $f = $this->calculated('RSR');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', ['search' => $f['enrollment']->enrollment_number]))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', ['search' => 'no-such-student-xyz']))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);
    }

    public function test_results_can_be_filtered_by_program_and_section(): void
    {
        $f = $this->calculated('RSS');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', ['program_id' => $f['ctx']['prog']->id, 'section_id' => $f['ctx']['sec']->id]))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);

        $otherSection = \App\Models\Section::create([
            'college_id' => $f['college']->id,
            'academic_year_id' => $f['ctx']['year']->id,
            'program_id' => $f['ctx']['prog']->id,
            'name' => 'Empty Section',
            'code' => 'ES-RSS',
            'status' => 'active',
        ]);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', ['section_id' => $otherSection->id]))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);
    }

    public function test_results_can_be_filtered_by_academic_year_and_term(): void
    {
        $f = $this->calculated('RST');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index', [
                'academic_year_id' => $f['ctx']['year']->id,
                'academic_term_id' => $f['ctx']['term']->id,
            ]))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);
    }

    // --------------------------------------------------------------- pagination

    public function test_results_pagination_is_deterministic(): void
    {
        $college = $this->makeCollege('RSU');
        $ctx = $this->makeExamContextWithSubjects($college, 'RSU', 2);
        $scale = $this->makeGradeScale($college);

        for ($i = 1; $i <= 20; $i++) {
            [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RSU'.$i);
            $this->recordMark($ctx['schedules'][0], $enrollment, 60.0);
            $this->recordMark($ctx['schedules'][1], $enrollment, 60.0);
        }

        $calculator = $this->makeUserWithPermissions($college, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $user = $this->makeUserWithPermissions($college, self::VIEW_UNPUBLISHED);

        $this->assertSame(20, DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->count());

        $pageOne = $this->asCollege($college, $user)->get(route('results.index'))->assertOk();
        $pageTwo = $this->asCollege($college, $user)->get(route('results.index', ['page' => 2]))->assertOk();

        // 20 results over a 15-per-page list: 15 rows on page one, 5 on page two.
        $this->assertSame(15, substr_count($pageOne->getContent(), '/results/'));
        $this->assertSame(5, substr_count($pageTwo->getContent(), '/results/'));

        // The pages are genuinely different.
        $this->assertNotSame($pageOne->getContent(), $pageTwo->getContent());

        // Ordering is stable: newest first, so the two pages are disjoint and
        // together cover every result exactly once.
        $newestFirst = DB::table('exam_results')
            ->where('examination_id', $ctx['exam']->id)
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        $firstPageIds = array_slice($newestFirst, 0, 15);
        $secondPageIds = array_slice($newestFirst, 15, 5);

        foreach ($firstPageIds as $id) {
            $this->assertStringContainsString('/results/'.$id, $pageOne->getContent());
            $this->assertStringNotContainsString('/results/'.$id, $pageTwo->getContent());
        }

        foreach ($secondPageIds as $id) {
            $this->assertStringContainsString('/results/'.$id, $pageTwo->getContent());
        }
    }

    // ---------------------------------------------------------- tenant isolation

    public function test_results_of_another_college_are_never_listed(): void
    {
        $f = $this->calculated('RSV');
        $other = $this->makeCollege('RSW');

        $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW_UNPUBLISHED))
            ->get(route('results.index'))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);
    }

    public function test_a_result_from_another_college_cannot_be_opened(): void
    {
        $f = $this->calculated('RSX');
        $other = $this->makeCollege('RSY');

        $this->asCollege($other, $this->makeUserWithPermissions($other, self::VIEW_UNPUBLISHED))
            ->get(route('results.show', $f['result']))
            ->assertNotFound();
    }

    public function test_soft_deleted_results_disappear_from_the_list_and_detail(): void
    {
        $f = $this->calculated('RSZ');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $this->asCollege($f['college'], $user)
            ->get(route('results.index'))
            ->assertOk()
            ->assertSee($f['enrollment']->enrollment_number);

        $this->withTenant($f['college'], fn () => $f['result']->delete());

        $this->asCollege($f['college'], $user)
            ->get(route('results.index'))
            ->assertOk()
            ->assertDontSee($f['enrollment']->enrollment_number);

        $this->asCollege($f['college'], $user)
            ->get(route('results.show', $f['result']))
            ->assertNotFound();
    }

    // ------------------------------------------------------------ status display

    public function test_absent_results_render_as_absent_not_as_a_score(): void
    {
        $f = $this->calculated('RTA', [80.0, 70.0], ExamMark::STATUS_ABSENT);

        $this->assertSame('absent', $f['result']->result_status);

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED))
            ->get(route('results.show', $f['result']))
            ->assertOk()
            ->assertSee('Absent');
    }

    public function test_withheld_results_render_as_withheld(): void
    {
        $f = $this->calculated('RTB', [80.0, 70.0], ExamMark::STATUS_WITHHELD);

        $this->assertSame('withheld', $f['result']->result_status);

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED))
            ->get(route('results.show', $f['result']))
            ->assertOk()
            ->assertSee('Withheld');
    }

    public function test_reading_results_never_writes_marks_or_results(): void
    {
        $f = $this->calculated('RTC');
        $user = $this->makeUserWithPermissions($f['college'], self::VIEW_UNPUBLISHED);

        $marksBefore = DB::table('exam_marks')->get()->map(fn ($r) => (array) $r)->all();
        $resultsBefore = DB::table('exam_results')->get()->map(fn ($r) => (array) $r)->all();

        $this->asCollege($f['college'], $user)->get(route('results.index'))->assertOk();
        $this->asCollege($f['college'], $user)->get(route('results.show', $f['result']))->assertOk();

        $this->assertSame($marksBefore, DB::table('exam_marks')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($resultsBefore, DB::table('exam_results')->get()->map(fn ($r) => (array) $r)->all());
    }
}
