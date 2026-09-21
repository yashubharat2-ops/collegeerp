<?php

namespace Tests\Feature\Results;

use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\GradeScale;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Result Publishing (Examinations Phase 3).
 *
 * Publishing is a separate, explicitly authorized step: calculation never
 * publishes, only a calculated result with a usable grading configuration may
 * be published, and unpublishing needs its own permission.
 */
class ResultPublishingTest extends TestCase
{
    use ResultTestHelpers;

    private const PUBLISH = [
        'results.view',
        'result_publishing.view',
        'result_publishing.publish',
        'result_publishing.unpublish',
    ];

    /**
     * A calculated, publishable result plus its fixture.
     *
     * @return array<string, mixed>
     */
    private function calculatedResult(string $prefix = 'RPA', array $marks = [80.0, 70.0]): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix);
        $scale = $this->makeGradeScale($college);

        $this->recordMark($ctx['schedules'][0], $enrollment, $marks[0]);
        $this->recordMark($ctx['schedules'][1], $enrollment, $marks[1]);

        $user = $this->makeUserWithPermissions($college, [
            'results.view',
            'result_calculation.view',
            'result_calculation.calculate',
            'result_calculation.recalculate',
        ]);

        $this->asCollege($college, $user)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        return compact('college', 'ctx', 'student', 'enrollment', 'scale', 'result');
    }

    /** @param  array<string, mixed>  $f */
    private function publisher(array $f): \App\Models\User
    {
        return $this->makeUserWithPermissions($f['college'], self::PUBLISH);
    }

    // ------------------------------------------------------------------ publish

    public function test_a_calculated_result_can_be_published(): void
    {
        $f = $this->calculatedResult();

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $result = $f['result']->refresh();

        $this->assertTrue($result->isPublished());
        $this->assertSame('published', $result->publication_status);
        $this->assertNotNull($result->published_at);
        $this->assertNotNull($result->published_by);
    }

    public function test_calculation_never_publishes_on_its_own(): void
    {
        $f = $this->calculatedResult('RPB');

        $this->assertFalse($f['result']->isPublished());
        $this->assertSame('unpublished', $f['result']->publication_status);
        $this->assertNull($f['result']->published_at);
        $this->assertNull($f['result']->published_by);
    }

    public function test_publishing_an_incomplete_result_is_blocked(): void
    {
        $f = $this->calculatedResult('RPC');

        // Remove one required mark and recalculate → incomplete.
        $this->withTenant($f['college'], function () use ($f): void {
            ExamMark::query()
                ->where('exam_schedule_id', $f['ctx']['schedules'][1]->id)
                ->where('student_enrollment_id', $f['enrollment']->id)
                ->firstOrFail()
                ->update(['status' => ExamMark::STATUS_DRAFT, 'obtained_marks' => null]);
        });

        $user = $this->makeUserWithPermissions($f['college'], [
            'results.view', 'result_calculation.view', 'result_calculation.calculate', 'result_calculation.recalculate',
        ]);

        $this->asCollege($f['college'], $user)
            ->post(route('result-calculation.recalculate'), [
                'examination_id' => $f['ctx']['exam']->id,
                'grade_scale_id' => $f['scale']->id,
            ])
            ->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));
        $this->assertSame(ExamResult::CALCULATION_INCOMPLETE, $result->calculation_status);

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $result))
            ->assertRedirect()
            ->assertSessionHasErrors('result');

        $this->assertFalse($result->refresh()->isPublished(), 'Incomplete results must never be published.');
    }

    public function test_publishing_a_failed_calculation_is_blocked(): void
    {
        $f = $this->calculatedResult('RPD');

        // Break the captured marks (above max) so validation fails.
        $this->withTenant($f['college'], function () use ($f): void {
            ExamMark::query()
                ->where('exam_schedule_id', $f['ctx']['schedules'][0]->id)
                ->where('student_enrollment_id', $f['enrollment']->id)
                ->firstOrFail()
                ->update(['obtained_marks' => 500.0]);
        });

        $user = $this->makeUserWithPermissions($f['college'], [
            'results.view', 'result_calculation.view', 'result_calculation.calculate', 'result_calculation.recalculate',
        ]);

        $this->asCollege($f['college'], $user)
            ->post(route('result-calculation.recalculate'), [
                'examination_id' => $f['ctx']['exam']->id,
                'grade_scale_id' => $f['scale']->id,
            ])
            ->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));
        $this->assertSame(ExamResult::CALCULATION_FAILED, $result->calculation_status);

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $result))
            ->assertRedirect()
            ->assertSessionHasErrors('result');

        $this->assertFalse($result->refresh()->isPublished());
    }

    public function test_publishing_is_blocked_when_the_grading_configuration_is_invalid(): void
    {
        $f = $this->calculatedResult('RPE');

        // Break the scale so the achieved percentage no longer resolves.
        $this->withTenant($f['college'], fn () => $f['scale']->items()->update(['max_percentage' => 39.99]));

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect()
            ->assertSessionHasErrors('result');

        $this->assertFalse($f['result']->refresh()->isPublished());
    }

    public function test_publishing_a_second_time_is_rejected(): void
    {
        $f = $this->calculatedResult('RPF');

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect()
            ->assertSessionHasErrors('result');
    }

    // ---------------------------------------------------------------- unpublish

    public function test_an_authorized_user_can_unpublish(): void
    {
        $f = $this->calculatedResult('RPG');
        $user = $this->publisher($f);

        $this->asCollege($f['college'], $user)->post(route('result-publishing.publish', $f['result']))->assertRedirect();
        $this->assertTrue($f['result']->refresh()->isPublished());

        $this->asCollege($f['college'], $user)
            ->post(route('result-publishing.unpublish', $f['result']))
            ->assertRedirect();

        $result = $f['result']->refresh();
        $this->assertFalse($result->isPublished());
        $this->assertNull($result->published_at);
        $this->assertNull($result->published_by);
    }

    public function test_unpublishing_requires_the_dedicated_permission(): void
    {
        $f = $this->calculatedResult('RPH');

        $publisherOnly = $this->makeUserWithPermissions($f['college'], [
            'results.view', 'result_publishing.view', 'result_publishing.publish',
        ]);

        $this->asCollege($f['college'], $publisherOnly)
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $this->asCollege($f['college'], $publisherOnly)
            ->post(route('result-publishing.unpublish', $f['result']))
            ->assertForbidden();

        $this->assertTrue($f['result']->refresh()->isPublished(), 'An unauthorized unpublish must not take effect.');
    }

    // -------------------------------------------------------------- bulk publish

    public function test_bulk_publish_publishes_every_selected_eligible_result(): void
    {
        $f = $this->calculatedResult('RPI');

        // A second student in the same section, also calculated.
        [$s2, $e2] = $this->makeEnrolledStudent($f['college'], $f['ctx'], 'RPI2');
        $this->recordMark($f['ctx']['schedules'][0], $e2, 90.0);
        $this->recordMark($f['ctx']['schedules'][1], $e2, 90.0);

        $calculator = $this->makeUserWithPermissions($f['college'], [
            'results.view', 'result_calculation.view', 'result_calculation.calculate',
        ]);

        $this->asCollege($f['college'], $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $f['ctx']['exam']->id,
                'grade_scale_id' => $f['scale']->id,
            ])
            ->assertRedirect();

        $ids = DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->pluck('id')->all();
        $this->assertCount(2, $ids);

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.bulk'), ['result_ids' => $ids])
            ->assertRedirect()
            ->assertSessionHas('success');

        $published = DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->whereNotNull('published_at')->count();
        $this->assertSame(2, $published);
    }

    public function test_bulk_publish_skips_results_that_are_not_eligible(): void
    {
        $college = $this->makeCollege('RPJ');
        $ctx = $this->makeExamContextWithSubjects($college, 'RPJ', 2);
        $scale = $this->makeGradeScale($college);

        // Student 1: complete marks → calculated + publishable.
        [, $e1] = $this->makeEnrolledStudent($college, $ctx, 'RPJ1');
        $this->recordMark($ctx['schedules'][0], $e1, 80.0);
        $this->recordMark($ctx['schedules'][1], $e1, 70.0);

        // Student 2: one mark missing → incomplete, must stay unpublished.
        [, $e2] = $this->makeEnrolledStudent($college, $ctx, 'RPJ2');
        $this->recordMark($ctx['schedules'][0], $e2, 80.0);

        $calculator = $this->makeUserWithPermissions($college, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])
            ->assertRedirect();

        $user = $this->makeUserWithPermissions($college, self::PUBLISH);

        $rows = DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->get();
        $this->assertCount(2, $rows);

        $this->asCollege($college, $user)
            ->post(route('result-publishing.bulk'), ['result_ids' => $rows->pluck('id')->all()])
            ->assertRedirect()
            ->assertSessionHas('success');

        $publishedIds = DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->whereNotNull('published_at')->pluck('student_enrollment_id')->all();
        $this->assertSame([$e1->id], $publishedIds, 'Only the complete result may be published.');
    }

    public function test_bulk_publish_is_transactional_when_nothing_is_eligible(): void
    {
        $college = $this->makeCollege('RPK');
        $ctx = $this->makeExamContextWithSubjects($college, 'RPK', 2);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RPK');

        // No marks at all → incomplete.
        $calculator = $this->makeUserWithPermissions($college, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), ['examination_id' => $ctx['exam']->id])
            ->assertRedirect();

        $user = $this->makeUserWithPermissions($college, self::PUBLISH);
        $ids = DB::table('exam_results')->pluck('id')->all();

        $this->asCollege($college, $user)
            ->post(route('result-publishing.bulk'), ['result_ids' => $ids])
            ->assertRedirect()
            ->assertSessionHasErrors('result_ids');

        $this->assertSame(0, DB::table('exam_results')->whereNotNull('published_at')->count());
    }

    public function test_publishing_all_eligible_results_of_an_examination(): void
    {
        $f = $this->calculatedResult('RPL');

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.examination', $f['ctx']['exam']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->whereNotNull('published_at')->count());
    }

    // ------------------------------------------------------------- tenant safety

    public function test_a_result_from_another_college_cannot_be_published(): void
    {
        $f = $this->calculatedResult('RPM');
        $other = $this->makeCollege('RPN');

        $foreignCtx = $this->makeExamContextWithSubjects($other, 'RPN', 2);
        [, $foreignEnrollment] = $this->makeEnrolledStudent($other, $foreignCtx, 'RPN');
        $this->recordMark($foreignCtx['schedules'][0], $foreignEnrollment, 80.0);
        $this->recordMark($foreignCtx['schedules'][1], $foreignEnrollment, 70.0);

        $foreignCalc = $this->makeUserWithPermissions($other, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($other, $foreignCalc)
            ->post(route('result-calculation.calculate'), ['examination_id' => $foreignCtx['exam']->id])
            ->assertRedirect();

        $foreignResultId = (int) DB::table('exam_results')->where('examination_id', $foreignCtx['exam']->id)->value('id');

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $foreignResultId))
            ->assertNotFound();

        $this->assertSame(0, DB::table('exam_results')->whereKey($foreignResultId)->whereNotNull('published_at')->count());
    }

    public function test_a_result_from_another_college_cannot_be_unpublished(): void
    {
        $f = $this->calculatedResult('RPO');
        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $other = $this->makeCollege('RPP');
        $this->asCollege($other, $this->makeUserWithPermissions($other, self::PUBLISH))
            ->post(route('result-publishing.unpublish', $f['result']))
            ->assertNotFound();

        $this->assertTrue($f['result']->refresh()->isPublished());
    }

    public function test_bulk_publish_ignores_ids_from_another_college(): void
    {
        $f = $this->calculatedResult('RPQ');
        $other = $this->makeCollege('RPR');

        $foreignCtx = $this->makeExamContextWithSubjects($other, 'RPR', 2);
        [, $foreignEnrollment] = $this->makeEnrolledStudent($other, $foreignCtx, 'RPR');
        $this->recordMark($foreignCtx['schedules'][0], $foreignEnrollment, 80.0);
        $this->recordMark($foreignCtx['schedules'][1], $foreignEnrollment, 70.0);

        $foreignCalc = $this->makeUserWithPermissions($other, ['results.view', 'result_calculation.view', 'result_calculation.calculate']);
        $this->asCollege($other, $foreignCalc)
            ->post(route('result-calculation.calculate'), ['examination_id' => $foreignCtx['exam']->id])
            ->assertRedirect();

        $foreignId = (int) DB::table('exam_results')->where('examination_id', $foreignCtx['exam']->id)->value('id');

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.bulk'), ['result_ids' => [$foreignId]])
            ->assertRedirect()
            // The Form Request's tenant-scoped exists() rule rejects the id
            // before it can ever reach the publishing service.
            ->assertSessionHasErrors('result_ids.0');

        $this->assertSame(0, DB::table('exam_results')->whereKey($foreignId)->whereNotNull('published_at')->count());
    }

    // ---------------------------------------------------------------------- RBAC

    public function test_publishing_requires_the_publish_permission(): void
    {
        $f = $this->calculatedResult('RPS');
        $viewer = $this->makeUserWithPermissions($f['college'], ['results.view', 'result_publishing.view']);

        $this->asCollege($f['college'], $viewer)
            ->post(route('result-publishing.publish', $f['result']))
            ->assertForbidden();

        $this->assertFalse($f['result']->refresh()->isPublished());
    }

    public function test_the_publishing_screen_requires_the_view_permission(): void
    {
        $f = $this->calculatedResult('RPT');

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['students.view']))
            ->get(route('result-publishing.index'))
            ->assertForbidden();

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], ['result_publishing.view']))
            ->get(route('result-publishing.index'))
            ->assertOk();
    }

    public function test_super_admin_can_publish_and_unpublish(): void
    {
        $f = $this->calculatedResult('RPU');
        $super = $this->makeSuperAdmin($f['college']);

        $this->asCollege($f['college'], $super)
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $this->assertTrue($f['result']->refresh()->isPublished());

        $this->asCollege($f['college'], $super)
            ->post(route('result-publishing.unpublish', $f['result']))
            ->assertRedirect();

        $this->assertFalse($f['result']->refresh()->isPublished());
    }

    // -------------------------------------------------------------------- audit

    public function test_publish_and_unpublish_are_audit_logged(): void
    {
        $f = $this->calculatedResult('RPV');
        $user = $this->publisher($f);

        $this->asCollege($f['college'], $user)->post(route('result-publishing.publish', $f['result']))->assertRedirect();
        $this->asCollege($f['college'], $user)->post(route('result-publishing.unpublish', $f['result']))->assertRedirect();

        $base = ['college_id' => $f['college']->id, 'user_id' => $user->id, 'subject_type' => $f['result']->getMorphClass(), 'subject_id' => $f['result']->id];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'results.published']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'results.unpublished']);
    }

    public function test_bulk_publish_is_audit_logged(): void
    {
        $f = $this->calculatedResult('RPW');
        $user = $this->publisher($f);

        $this->asCollege($f['college'], $user)
            ->post(route('result-publishing.bulk'), ['result_ids' => [$f['result']->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'results.bulk_published',
            'college_id' => $f['college']->id,
            'user_id' => $user->id,
        ]);
    }

    // ----------------------------------------------------- status independence

    public function test_publishing_does_not_change_the_calculation_or_result_status(): void
    {
        $f = $this->calculatedResult('RPX');

        $before = [$f['result']->calculation_status, $f['result']->result_status, (string) $f['result']->percentage];

        $this->asCollege($f['college'], $this->publisher($f))
            ->post(route('result-publishing.publish', $f['result']))
            ->assertRedirect();

        $after = [$f['result']->refresh()->calculation_status, $f['result']->result_status, (string) $f['result']->percentage];

        $this->assertSame($before, $after, 'Publishing only flips the publication state.');
    }
}
