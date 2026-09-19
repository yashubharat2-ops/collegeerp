<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use Tests\TestCase;

/**
 * Student promotion.
 *
 * The invariants that matter: promotion is additive (the source enrollment is
 * preserved, never deleted), the target enrollment is created with a
 * server-generated number, duplicate targets are blocked, contextual target
 * references are validated, and every step is authorized and audited.
 */
class StudentPromotionTest extends TestCase
{
    use StudentTestHelpers;

    private function payload(int $studentId, int $sourceEnrollmentId, int $targetYearId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'source_enrollment_id' => $sourceEnrollmentId,
            'target_academic_year_id' => $targetYearId,
            'remarks' => 'Annual promotion',
        ], $overrides);
    }

    public function test_index_and_create_require_their_own_permissions(): void
    {
        $college = $this->makeCollege('PRMPERM');
        $viewer = $this->makeUserWithPermissions($college, ['student_promotions.view']);

        $this->asCollege($college, $viewer)->get(route('student-promotions.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('student-promotions.create'))->assertForbidden();

        $nobody = $this->makeUserWithPermissions($college, []);
        $this->asCollege($college, $nobody)->get(route('student-promotions.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student-promotions.index'))->assertRedirect(route('login'));
    }

    public function test_promotion_request_is_recorded_as_pending_without_touching_enrollments(): void
    {
        $college = $this->makeCollege('PRMREQ');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $sectionA = $this->makeSection($college, $year, $program, 'A');
        $sectionB = $this->makeSection($college, $nextYear, $program, 'B');
        $term = $this->makeAcademicTerm($college, $nextYear, 'SEM1', 'Semester 1');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program, ['section_id' => $sectionA->id]);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionB->id,
                'target_academic_term_id' => $term->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-promotions.index'));

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->assertNotNull($promotion);
        $this->assertSame('pending', $promotion->status);
        $this->assertNull($promotion->target_enrollment_id);
        $this->assertNull($promotion->approved_at);
        $this->assertSame($year->id, $promotion->source_academic_year_id, 'Source context is snapshotted.');
        $this->assertSame($sectionA->id, $promotion->source_section_id);
        $this->assertSame($nextYear->id, $promotion->target_academic_year_id);
        $this->assertSame($sectionB->id, $promotion->target_section_id);
        $this->assertSame($term->id, $promotion->target_academic_term_id);

        // Nothing enrolled yet.
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('active', $source->fresh()->status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.requested']);
    }

    public function test_approving_creates_the_target_enrollment_and_preserves_the_source(): void
    {
        $college = $this->makeCollege('PRMAPP');
        $creator = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $approver = $this->makeUserWithPermissions($college, ['student_promotions.view', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $sectionA = $this->makeSection($college, $year, $program, 'A');
        $sectionB = $this->makeSection($college, $nextYear, $program, 'B');
        $student = $this->makeStudent($college, ['student_number' => 'STU-PROM-1']);
        $source = $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-SRC-1',
            'section_id' => $sectionA->id,
        ]);

        $this->asCollege($college, $creator)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionB->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $approver)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHas('success')
            ->assertRedirect(route('student-promotions.index'));

        $promotion->refresh();
        $this->assertSame('approved', $promotion->status);
        $this->assertNotNull($promotion->approved_at);
        $this->assertSame($approver->id, $promotion->approved_by);
        $this->assertNotNull($promotion->target_enrollment_id);

        // Target enrollment created with a server-generated number and section.
        $target = StudentEnrollment::withoutGlobalScopes()->find($promotion->target_enrollment_id);
        $this->assertNotNull($target);
        $this->assertSame($student->id, $target->student_id);
        $this->assertSame($nextYear->id, $target->academic_year_id);
        $this->assertSame($program->id, $target->program_id);
        $this->assertSame($sectionB->id, $target->section_id);
        $this->assertSame('active', $target->status);
        $this->assertStringStartsWith('ENR-', $target->enrollment_number);
        $this->assertNotSame('ENR-SRC-1', $target->enrollment_number);

        // The source enrollment is preserved — never deleted, only completed.
        $source->refresh();
        $this->assertNull($source->deleted_at, 'The historical enrollment row must survive.');
        $this->assertSame('completed', $source->status);
        $this->assertSame('ENR-SRC-1', $source->enrollment_number);
        $this->assertSame($sectionA->id, $source->section_id);

        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.updated']);
    }

    public function test_a_creator_cannot_approve_their_own_request(): void
    {
        $college = $this->makeCollege('PRMNOAP');
        $creator = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $creator)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $creator)->post(route('student-promotions.approve', $promotion))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('student-promotions.cancel', $promotion))->assertForbidden();

        $this->assertSame('pending', $promotion->fresh()->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_target_enrollment_is_blocked(): void
    {
        $college = $this->makeCollege('PRMDUP');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.view', 'student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);
        // The student is already enrolled in the target year/program.
        $this->makeEnrollment($college, $student, $nextYear, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, ['target_program_id' => $program->id]))
            ->assertSessionHasErrors('target_academic_year_id');

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_pending_request_for_the_same_target_is_blocked(): void
    {
        $college = $this->makeCollege('PRMPEND');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $sectionB = $this->makeSection($college, $nextYear, $program, 'B');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $payload = $this->payload($student->id, $source->id, $nextYear->id, [
            'target_program_id' => $program->id,
            'target_section_id' => $sectionB->id,
        ]);

        $this->asCollege($college, $admin)->post(route('student-promotions.store'), $payload)->assertSessionHasNoErrors();
        $this->asCollege($college, $admin)->post(route('student-promotions.store'), $payload)->assertSessionHasErrors('target_academic_year_id');

        $this->assertSame(1, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_invalid_targets_are_rejected(): void
    {
        $college = $this->makeCollege('PRMINV');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college, 'BCOM');
        $otherProgram = $this->makeProgram($college, 'BSC');
        $sectionOtherProgram = $this->makeSection($college, $nextYear, $otherProgram, 'C');
        $sectionOtherYear = $this->makeSection($college, $year, $program, 'A');
        $termOtherYear = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // Target year identical to the source enrollment's year.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $year->id))
            ->assertSessionHasErrors('target_academic_year_id');
        $this->assertSame(
            0,
            StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count(),
            'A same-year promotion must be refused before anything is persisted.'
        );

        // Section belonging to another program.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionOtherProgram->id,
            ]))
            ->assertSessionHasErrors('target_section_id');

        // Section belonging to another academic year.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionOtherYear->id,
            ]))
            ->assertSessionHasErrors('target_section_id');

        // Term belonging to another academic year.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_academic_term_id' => $termOtherYear->id,
            ]))
            ->assertSessionHasErrors('target_academic_term_id');

        // Source enrollment of another student.
        $other = $this->makeStudent($college, ['student_number' => 'STU-OTHER']);
        $otherEnrollment = $this->makeEnrollment($college, $other, $year, $program);
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $otherEnrollment->id, $nextYear->id))
            ->assertSessionHasErrors('source_enrollment_id');

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_promotion_cannot_be_approved_twice(): void
    {
        $college = $this->makeCollege('PRMTWICE');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, ['target_program_id' => $program->id]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHasErrors('status');

        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_failed_approval_leaves_no_partial_state(): void
    {
        $college = $this->makeCollege('PRMTXN');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, ['target_program_id' => $program->id]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        // A target enrollment appears between request and approval: the
        // transaction must refuse the promotion and change nothing.
        $this->makeEnrollment($college, $student, $nextYear, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHasErrors('target_academic_year_id');

        $promotion->refresh();
        $this->assertSame('pending', $promotion->status);
        $this->assertNull($promotion->target_enrollment_id);
        $this->assertSame('active', $source->fresh()->status, 'The source enrollment must not be completed by a failed promotion.');
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_pending_promotion_can_be_cancelled(): void
    {
        $college = $this->makeCollege('PRMCAN');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)->post(route('student-promotions.cancel', $promotion))->assertSessionHas('success');
        $this->assertSame('cancelled', $promotion->fresh()->status);
        $this->assertSame('active', $source->fresh()->status);

        // A cancelled promotion can no longer be approved or cancelled again.
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHasErrors('status');
        $this->asCollege($college, $admin)->post(route('student-promotions.cancel', $promotion))->assertSessionHasErrors('status');

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.cancelled']);
    }

    public function test_promotion_does_not_encode_any_progression_rule(): void
    {
        $college = $this->makeCollege('PRMRULE');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $from = $this->makeProgram($college, 'BCOM');
        $into = $this->makeProgram($college, 'BA');
        $sectionBA = $this->makeSection($college, $nextYear, $into, 'D');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $from);

        // A lateral move into a different program/section is a valid promotion.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $into->id,
                'target_section_id' => $sectionBA->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        $target = StudentEnrollment::withoutGlobalScopes()->find($promotion->fresh()->target_enrollment_id);
        $this->assertSame($into->id, $target->program_id);
        $this->assertSame($sectionBA->id, $target->section_id);
    }
}
