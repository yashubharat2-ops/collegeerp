<?php

namespace Tests\Feature\Results;

use App\Models\AuditLog;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamResultItem;
use App\Models\GradeScale;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Result Calculation (Examinations Phase 3).
 *
 * The engine is driven end-to-end through the HTTP routes so RBAC, tenant
 * isolation and audit logging are exercised together with the domain rules.
 *
 * ExamMark stays the single source of truth throughout: calculation only ever
 * reads it and writes ExamResult / ExamResultItem snapshots.
 */
class ResultCalculationTest extends TestCase
{
    use ResultTestHelpers;

    private const VIEW = ['results.view', 'result_calculation.view'];

    private const CALCULATE = [
        'results.view',
        'result_calculation.view',
        'result_calculation.calculate',
        'result_calculation.recalculate',
    ];

    /**
     * Two subjects, one student, all marks entered and passed.
     *
     * @return array<string, mixed>
     */
    private function passingFixture(string $prefix = 'RCP'): array
    {
        $college = $this->makeCollege($prefix);
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix);

        $this->recordMark($ctx['schedules'][0], $enrollment, 80.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 70.0);

        return compact('college', 'ctx', 'student', 'enrollment');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function calculate(array $fixture, array $overrides = [], bool $recalculate = false): \Illuminate\Testing\TestResponse
    {
        $user = $this->makeUserWithPermissions($fixture['college'], self::CALCULATE);

        $payload = array_merge([
            'examination_id' => $fixture['ctx']['exam']->id,
        ], $overrides);

        return $this->asCollege($fixture['college'], $user)
            ->post(route($recalculate ? 'result-calculation.recalculate' : 'result-calculation.calculate'), $payload);
    }

    // -------------------------------------------------------------- happy path

    public function test_calculation_creates_one_snapshot_per_enrollment_with_subject_rows(): void
    {
        $f = $this->passingFixture('RCA');
        $scale = $this->makeGradeScale($f['college']);

        $this->calculate($f, ['grade_scale_id' => $scale->id])->assertRedirect();

        $this->assertSame(1, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->count());

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertNotNull($result, 'A result snapshot must be created.');
        $this->assertSame('200.00', (string) $result->total_max_marks);
        $this->assertSame('150.00', (string) $result->total_obtained_marks);
        $this->assertSame('75.000', (string) $result->percentage);
        $this->assertSame('pass', $result->result_status);
        $this->assertSame(ExamResult::CALCULATION_CALCULATED, $result->calculation_status);
        $this->assertSame('B', $result->overall_grade, '75% resolves to the B band of the test scale.');
        $this->assertNull($result->published_at, 'Calculation must never publish.');
        $this->assertSame('unpublished', $result->publication_status);

        $this->withTenant($f['college'], function () use ($result): void {
            $this->assertSame(2, $result->items()->count());
            $this->assertSame(['pass', 'pass'], $result->items()->pluck('status')->all());
            $this->assertSame(['80.00', '70.00'], $result->items()->pluck('obtained_marks')->map(fn ($v) => (string) $v)->all());
        });
    }

    public function test_calculation_records_processed_at_and_actor(): void
    {
        $f = $this->passingFixture('RCB');
        $user = $this->makeUserWithPermissions($f['college'], self::CALCULATE);

        $this->asCollege($f['college'], $user)
            ->post(route('result-calculation.calculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertNotNull($result->calculated_at);
        $this->assertNotNull($result->calculated_by);
        $this->assertNotNull($result->created_by);
    }

    public function test_calculation_works_without_a_grade_scale(): void
    {
        $f = $this->passingFixture('RCC');

        $this->calculate($f)->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertSame('pass', $result->result_status);
        $this->assertNull($result->overall_grade);
        $this->assertNull($result->grade_scale_id);
        $this->assertTrue($result->isPublishable(), 'Pass/fail is still derivable from each paper.');
    }

    // ------------------------------------------------------------- re-run safety

    public function test_recalculation_updates_the_existing_snapshot_instead_of_duplicating_it(): void
    {
        $f = $this->passingFixture('RCD');

        $this->calculate($f)->assertRedirect();
        $this->calculate($f, recalculate: true)->assertRedirect();

        $this->assertSame(1, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->count());
        $this->assertSame(2, DB::table('exam_result_items')->count());
    }

    public function test_calculating_twice_never_duplicates_results_or_items(): void
    {
        $f = $this->passingFixture('RCE');

        $this->calculate($f)->assertRedirect();
        $this->calculate($f)->assertRedirect();
        $this->calculate($f)->assertRedirect();

        $this->assertSame(1, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->count());
        $this->assertSame(2, DB::table('exam_result_items')->count());
    }

    public function test_recalculation_refreshes_totals_after_marks_change(): void
    {
        $f = $this->passingFixture('RCF');
        $scale = $this->makeGradeScale($f['college']);

        $this->calculate($f, ['grade_scale_id' => $scale->id])->assertRedirect();
        $before = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));
        $this->assertSame('150.00', (string) $before->total_obtained_marks);

        // Re-enter one subject's marks, then recalculate.
        $this->withTenant($f['college'], function () use ($f): void {
            ExamMark::query()
                ->where('exam_schedule_id', $f['ctx']['schedules'][1]->id)
                ->where('student_enrollment_id', $f['enrollment']->id)
                ->firstOrFail()
                ->update(['obtained_marks' => 90.0]);
        });

        $this->calculate($f, ['grade_scale_id' => $scale->id], recalculate: true)->assertRedirect();

        $after = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));
        $this->assertSame('170.00', (string) $after->total_obtained_marks);
        $this->assertSame('85.000', (string) $after->percentage);
        $this->assertSame('A', $after->overall_grade);
    }

    // ------------------------------------------------------------ result statuses

    public function test_a_failed_required_subject_prevents_an_overall_pass(): void
    {
        $f = $this->passingFixture('RCG');

        $this->withTenant($f['college'], function () use ($f): void {
            ExamMark::query()
                ->where('exam_schedule_id', $f['ctx']['schedules'][1]->id)
                ->where('student_enrollment_id', $f['enrollment']->id)
                ->firstOrFail()
                ->update(['obtained_marks' => 20.0]);
        });

        $this->calculate($f)->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertSame('fail', $result->result_status);
        $this->assertSame(ExamResult::CALCULATION_CALCULATED, $result->calculation_status);
        $this->assertNull($result->overall_grade, 'A failed result must not wear a passing grade.');
        $this->assertSame(['pass', 'fail'], $this->withTenant($f['college'], fn () => $result->items()->pluck('status')->all()));
    }

    public function test_absent_subjects_stay_absent_and_are_never_scored(): void
    {
        $f = $this->passingFixture('RCH');

        $this->withTenant($f['college'], function () use ($f): void {
            ExamMark::query()
                ->where('exam_schedule_id', $f['ctx']['schedules'][1]->id)
                ->where('student_enrollment_id', $f['enrollment']->id)
                ->firstOrFail()
                ->update(['status' => ExamMark::STATUS_ABSENT, 'obtained_marks' => null]);
        });

        $this->calculate($f)->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertSame('absent', $result->result_status);
        $this->assertNull(
            $this->withTenant($f['college'], fn () => $result->items()->where('status', 'absent')->firstOrFail())->obtained_marks,
            'Absent must never be silently converted to a score.'
        );
        $this->assertSame('80.00', (string) $result->total_obtained_marks, 'Only the scored paper contributes.');
        $this->assertSame('200.00', (string) $result->total_max_marks, 'The absent paper still counts in the denominator.');
    }

    public function test_withheld_subjects_stay_withheld_and_take_precedence(): void
    {
        $f = $this->passingFixture('RCI');

        $this->withTenant($f['college'], function () use ($f): void {
            ExamMark::query()
                ->where('exam_schedule_id', $f['ctx']['schedules'][1]->id)
                ->where('student_enrollment_id', $f['enrollment']->id)
                ->firstOrFail()
                ->update(['status' => ExamMark::STATUS_WITHHELD, 'obtained_marks' => null]);
        });

        $this->calculate($f)->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertSame('withheld', $result->result_status);
        $this->assertNull(
            $this->withTenant($f['college'], fn () => $result->items()->where('status', 'withheld')->firstOrFail())->obtained_marks
        );
    }

    public function test_draft_marks_are_not_treated_as_final(): void
    {
        $college = $this->makeCollege('RCJ');
        $ctx = $this->makeExamContextWithSubjects($college, 'RCJ', 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RCJ');

        $this->recordMark($ctx['schedules'][0], $enrollment, 90.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 90.0, ExamMark::STATUS_DRAFT);

        $this->calculate(compact('college', 'ctx', 'student', 'enrollment'))->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        $this->assertSame('incomplete', $result->result_status);
        $this->assertSame(ExamResult::CALCULATION_INCOMPLETE, $result->calculation_status);
        $this->assertNull(
            $this->withTenant($college, fn () => $result->items()->where('status', 'incomplete')->firstOrFail())->obtained_marks
        );
        $this->assertFalse($result->isPublishable(), 'A draft mark must never reach publication.');
    }

    public function test_missing_marks_produce_an_incomplete_result(): void
    {
        $college = $this->makeCollege('RCK');
        $ctx = $this->makeExamContextWithSubjects($college, 'RCK', 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RCK');

        // Only one of the two required papers was captured.
        $this->recordMark($ctx['schedules'][0], $enrollment, 55.0);

        $this->calculate(compact('college', 'ctx', 'student', 'enrollment'))->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        $this->assertSame('incomplete', $result->result_status);
        $this->assertSame(ExamResult::CALCULATION_INCOMPLETE, $result->calculation_status);
        $this->assertNull($result->percentage);
        $this->assertFalse($result->isPublishable());
    }

    public function test_invalid_marks_are_flagged_failed_and_never_guess_a_score(): void
    {
        $college = $this->makeCollege('RCL');
        $ctx = $this->makeExamContextWithSubjects($college, 'RCL', 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RCL');

        $this->recordMark($ctx['schedules'][0], $enrollment, 150.0); // above max (100)
        $this->recordMark($ctx['schedules'][1], $enrollment, 70.0);

        $this->calculate(compact('college', 'ctx', 'student', 'enrollment'))->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        $this->assertSame(ExamResult::CALCULATION_FAILED, $result->calculation_status);
        $this->assertNull($result->total_obtained_marks);
        $this->assertNull($result->percentage);
        $this->assertFalse($result->isPublishable());
    }

    public function test_negative_marks_are_rejected(): void
    {
        $college = $this->makeCollege('RCM');
        $ctx = $this->makeExamContextWithSubjects($college, 'RCM', 2);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RCM');

        $this->recordMark($ctx['schedules'][0], $enrollment, -10.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 70.0);

        $this->calculate(compact('college', 'ctx', 'student', 'enrollment'))->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));
        $this->assertSame(ExamResult::CALCULATION_FAILED, $result->calculation_status);
    }

    // ------------------------------------------------------------- grade scales

    public function test_a_cross_college_grade_scale_is_rejected(): void
    {
        $f = $this->passingFixture('RCN');
        $foreignScale = $this->makeGradeScale($this->makeCollege('RCO'));

        $this->calculate($f, ['grade_scale_id' => $foreignScale->id])->assertSessionHasErrors('grade_scale_id');

        $this->assertSame(0, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->count());
    }

    public function test_an_inactive_grade_scale_is_rejected(): void
    {
        $f = $this->passingFixture('RCP');
        $inactive = $this->makeGradeScale($f['college'], null, ['status' => GradeScale::STATUS_INACTIVE]);

        $this->calculate($f, ['grade_scale_id' => $inactive->id])->assertSessionHasErrors('grade_scale_id');
        $this->assertSame(0, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->count());
    }

    public function test_a_soft_deleted_grade_scale_is_rejected(): void
    {
        $f = $this->passingFixture('RCQ');
        $scale = $this->makeGradeScale($f['college']);
        $this->withTenant($f['college'], fn () => $scale->delete());

        $this->calculate($f, ['grade_scale_id' => $scale->id])->assertSessionHasErrors('grade_scale_id');
    }

    public function test_a_grade_scale_with_a_gap_blocks_publication_but_still_calculates(): void
    {
        $f = $this->passingFixture('RCR');
        // Bands cover 0-39.99 and 80-100 only: 75% falls into a gap.
        $gapped = $this->makeGradeScale($f['college'], [
            ['grade' => 'F', 'min_percentage' => 0, 'max_percentage' => 39.99, 'sort_order' => 1],
            ['grade' => 'A', 'min_percentage' => 80, 'max_percentage' => 100, 'sort_order' => 2],
        ]);

        $this->calculate($f, ['grade_scale_id' => $gapped->id])->assertRedirect();

        $result = $this->withTenant($f['college'], fn () => $this->resultFor($f['ctx']['exam'], $f['enrollment']));

        $this->assertSame(ExamResult::CALCULATION_CALCULATED, $result->calculation_status);
        $this->assertNull($result->overall_grade);

        // isPublishable() reads the grade scale through CollegeScope, so it has
        // to be evaluated with the tenant pinned.
        $this->withTenant($f['college'], function () use ($result): void {
            $this->assertNull($result->overall_grade);
            $this->assertFalse($result->isPublishable(), 'An unusable grading configuration blocks publishing.');
        });
    }

    // ------------------------------------------------------------ tenant safety

    public function test_a_cross_college_examination_cannot_be_calculated(): void
    {
        $f = $this->passingFixture('RCS');
        $other = $this->makeCollege('RCT');
        $foreignExam = $this->makeExamContextWithSubjects($other, 'RCT', 1)['exam'];

        $this->calculate($f, ['examination_id' => $foreignExam->id])->assertSessionHasErrors('examination_id');
        $this->assertSame(0, DB::table('exam_results')->count());
    }

    public function test_a_cross_college_enrollment_scope_is_rejected(): void
    {
        $f = $this->passingFixture('RCU');
        $other = $this->makeCollege('RCV');
        $foreignCtx = $this->makeExamContextWithSubjects($other, 'RCV', 1);
        [, $foreignEnrollment] = $this->makeEnrolledStudent($other, $foreignCtx, 'RCV');

        $this->calculate($f, ['student_enrollment_id' => $foreignEnrollment->id])
            ->assertSessionHasErrors('student_enrollment_id');

        $this->assertSame(0, DB::table('exam_results')->count());
    }

    public function test_a_cross_college_grade_scale_is_never_honoured_even_with_a_valid_examination(): void
    {
        $f = $this->passingFixture('RCW');
        $foreignScale = $this->makeGradeScale($this->makeCollege('RCX'));

        // Even a direct service call (bypassing the Form Request) must refuse.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->withTenant($f['college'], function () use ($f, $foreignScale): void {
            app(\App\Services\Examinations\ResultCalculationService::class)->calculate(
                $f['ctx']['exam'],
                $foreignScale,
                [],
                $this->makeUserWithPermissions($f['college'], self::CALCULATE),
            );
        });
    }

    public function test_results_are_isolated_per_college(): void
    {
        $f = $this->passingFixture('RCY');
        $other = $this->makeCollege('RCZ');

        $this->calculate($f)->assertRedirect();

        $this->withTenant($other, function (): void {
            $this->assertSame(0, ExamResult::query()->count());
            $this->assertSame(0, ExamResultItem::query()->count());
        });

        $this->assertSame(1, $this->withTenant($f['college'], fn () => ExamResult::query()->count()));
    }

    public function test_calculation_only_processes_eligible_enrollments(): void
    {
        $college = $this->makeCollege('RDA');
        $ctx = $this->makeExamContextWithSubjects($college, 'RDA', 2);
        [$s1, $e1] = $this->makeEnrolledStudent($college, $ctx, 'RDA1');
        [$s2, $e2] = $this->makeEnrolledStudent($college, $ctx, 'RDA2');

        // A third enrollment sitting in a DIFFERENT section is out of scope.
        $otherSection = \App\Models\Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'name' => 'Other Section',
            'code' => 'OS-RDA',
            'status' => 'active',
        ]);
        [, $outsider] = $this->makeEnrolledStudent($college, $ctx, 'RDA3', ['section_id' => $otherSection->id]);

        foreach ([$e1, $e2] as $enrollment) {
            $this->recordMark($ctx['schedules'][0], $enrollment, 60.0);
            $this->recordMark($ctx['schedules'][1], $enrollment, 60.0);
        }
        $this->recordMark($ctx['schedules'][0], $outsider, 60.0);

        $this->calculate(compact('college', 'ctx'))->assertRedirect();

        $this->assertSame(2, DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->count());
        $ids = DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->pluck('student_enrollment_id')->all();
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_a_single_student_scope_calculates_only_that_student(): void
    {
        $college = $this->makeCollege('RDB');
        $ctx = $this->makeExamContextWithSubjects($college, 'RDB', 2);
        [, $e1] = $this->makeEnrolledStudent($college, $ctx, 'RDB1');
        [, $e2] = $this->makeEnrolledStudent($college, $ctx, 'RDB2');

        foreach ([$e1, $e2] as $enrollment) {
            $this->recordMark($ctx['schedules'][0], $enrollment, 60.0);
            $this->recordMark($ctx['schedules'][1], $enrollment, 60.0);
        }

        $this->calculate(compact('college', 'ctx'), ['student_enrollment_id' => $e1->id])->assertRedirect();

        $ids = DB::table('exam_results')->where('examination_id', $ctx['exam']->id)->pluck('student_enrollment_id')->all();
        $this->assertSame([$e1->id], $ids);
    }

    // ---------------------------------------------------------------- transaction

    public function test_transaction_safety_leaves_no_partial_snapshot_when_the_run_fails(): void
    {
        $college = $this->makeCollege('RDC');
        $ctx = $this->makeExamContextWithSubjects($college, 'RDC', 2);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'RDC');

        $this->recordMark($ctx['schedules'][0], $enrollment, 60.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 60.0);

        // Force a failure inside the transaction by breaking the item table write.
        $user = $this->makeUserWithPermissions($college, self::CALCULATE);

        $this->withTenant($college, function () use ($ctx, $user, $enrollment): void {
            DB::listen(function (): void {});

            $failed = false;

            try {
                DB::transaction(function () use ($ctx, $user, $enrollment): void {
                    app(\App\Services\Examinations\ResultCalculationService::class)->calculate($ctx['exam'], null, [], $user);

                    throw new \RuntimeException('simulated failure');
                });
            } catch (\RuntimeException) {
                $failed = true;
            }

            $this->assertTrue($failed);
            $this->assertSame(0, DB::table('exam_results')->count(), 'The inner calculation must roll back with the outer transaction.');
            $this->assertSame(0, DB::table('exam_result_items')->count());
        });
    }

    public function test_calculation_does_not_write_exam_marks(): void
    {
        $f = $this->passingFixture('RDD');

        $before = DB::table('exam_marks')->get()->map(fn ($r) => (array) $r)->all();

        $this->calculate($f)->assertRedirect();

        $after = DB::table('exam_marks')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertSame($before, $after, 'ExamMark is the source of truth and must never be mutated by calculation.');
    }

    // --------------------------------------------------------------------- RBAC

    public function test_calculation_requires_the_calculate_permission(): void
    {
        $f = $this->passingFixture('RDE');
        $viewer = $this->makeUserWithPermissions($f['college'], self::VIEW);

        $this->asCollege($f['college'], $viewer)
            ->post(route('result-calculation.calculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertForbidden();

        $this->assertSame(0, DB::table('exam_results')->count());
    }

    public function test_recalculation_requires_the_recalculate_permission(): void
    {
        $f = $this->passingFixture('RDF');
        $calculator = $this->makeUserWithPermissions($f['college'], self::VIEW + ['result_calculation.calculate']);

        $this->asCollege($f['college'], $calculator)
            ->post(route('result-calculation.recalculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertForbidden();
    }

    public function test_calculation_screen_requires_the_view_permission(): void
    {
        $f = $this->passingFixture('RDG');
        $stranger = $this->makeUserWithPermissions($f['college'], ['students.view']);

        $this->asCollege($f['college'], $stranger)->get(route('result-calculation.index'))->assertForbidden();

        $this->asCollege($f['college'], $this->makeUserWithPermissions($f['college'], self::VIEW))
            ->get(route('result-calculation.index'))
            ->assertOk();
    }

    public function test_super_admin_can_calculate_and_recalculate(): void
    {
        $f = $this->passingFixture('RDH');
        $super = $this->makeSuperAdmin($f['college']);

        $this->asCollege($f['college'], $super)
            ->post(route('result-calculation.calculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertRedirect();

        $this->asCollege($f['college'], $super)
            ->post(route('result-calculation.recalculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertRedirect();

        $this->assertSame(1, DB::table('exam_results')->where('examination_id', $f['ctx']['exam']->id)->count());
    }

    // -------------------------------------------------------------------- audit

    public function test_calculation_and_recalculation_are_audit_logged(): void
    {
        $f = $this->passingFixture('RDI');
        $user = $this->makeUserWithPermissions($f['college'], self::CALCULATE);

        $this->asCollege($f['college'], $user)
            ->post(route('result-calculation.calculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertRedirect();

        $this->asCollege($f['college'], $user)
            ->post(route('result-calculation.recalculate'), ['examination_id' => $f['ctx']['exam']->id])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'results.calculated',
            'college_id' => $f['college']->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'results.recalculated',
            'college_id' => $f['college']->id,
            'user_id' => $user->id,
        ]);

        $entry = AuditLog::query()->where('action', 'results.calculated')->firstOrFail();
        $this->assertSame($f['ctx']['exam']->id, $entry->new_values['examination_id']);
        $this->assertSame(1, $entry->new_values['processed']);
    }
}
