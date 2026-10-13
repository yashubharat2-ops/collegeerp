<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Actions\PromoteStudent;
use App\Domain\Student\Services\StudentService;
use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
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

    private function assertPromotionStatusError(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the promotion state transition to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    /**
     * Run an operation expected to be refused by the shared eligibility guard
     * and return the validation errors it produced.
     */
    private function captureGuardErrors(callable $operation): array
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('Expected the eligibility guard to reject the promotion.');

        return [];
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
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.updated', 'subject_id' => $source->id]);
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

    public function test_approval_reloads_locked_state_after_request_was_cancelled(): void
    {
        $college = $this->makeCollege('PRMSTALC');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $stalePendingCopy = StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id);
        app(TenantContext::class)->set($college);
        $action = app(PromoteStudent::class);

        // Simulate a request that retained its pre-cancellation model instance.
        $action->cancel($promotion, (int) $college->id, (int) $admin->id);
        $this->assertSame('cancelled', StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id)->status);

        $this->assertPromotionStatusError(fn () => $action->approve($stalePendingCopy, (int) $college->id, (int) $admin->id));

        $fresh = StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->target_enrollment_id);
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.cancelled']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
    }

    public function test_cancellation_reloads_locked_state_after_request_was_approved(): void
    {
        $college = $this->makeCollege('PRMSTALA');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $stalePendingCopy = StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id);
        app(TenantContext::class)->set($college);
        $action = app(PromoteStudent::class);

        $approved = $action->approve($promotion, (int) $college->id, (int) $admin->id);
        $this->assertSame('approved', $approved->status);
        $this->assertPromotionStatusError(fn () => $action->cancel($stalePendingCopy, (int) $college->id, (int) $admin->id));

        $fresh = StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertNotNull($fresh->target_enrollment_id);
        $this->assertSame('completed', $source->fresh()->status);
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.cancelled']);
    }

    public function test_approval_revalidates_source_enrollment_belongs_to_locked_student(): void
    {
        $college = $this->makeCollege('PRMSRC');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);
        $otherStudent = $this->makeStudent($college);
        $otherSource = $this->makeEnrollment($college, $otherStudent, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $stalePendingCopy = StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id);

        // Simulate a stale/mismatched relationship in storage after request
        // creation; approval must re-read the promotion and scope the source to
        // both this college and the locked promotion student.
        StudentPromotion::withoutGlobalScopes()
            ->whereKey($promotion->id)
            ->update(['source_enrollment_id' => $otherSource->id]);
        app(TenantContext::class)->set($college);
        $action = app(PromoteStudent::class);

        try {
            $action->approve($stalePendingCopy, (int) $college->id, (int) $admin->id);
            $this->fail('Expected a mismatched source enrollment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_enrollment_id', $exception->errors());
        }

        $fresh = StudentPromotion::withoutGlobalScopes()->findOrFail($promotion->id);
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->target_enrollment_id);
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('active', $otherSource->fresh()->status);
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
    }

    public function test_approval_rejects_a_student_withdrawn_after_the_request_was_created(): void
    {
        $college = $this->makeCollege('PRMWSTD');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, ['target_program_id' => $program->id]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        // Simulate TC withdrawal after the pending request was made.
        Student::withoutGlobalScopes()->whereKey($student->id)->update(['status' => 'withdrawn']);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHasErrors('student_id');

        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame('pending', $promotion->fresh()->status);
        $this->assertNull($promotion->fresh()->target_enrollment_id);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.updated']);
    }

    public function test_approval_rejects_a_withdrawn_source_enrollment_after_the_request_was_created(): void
    {
        $college = $this->makeCollege('PRMWSRC');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, ['target_program_id' => $program->id]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        StudentEnrollment::withoutGlobalScopes()->whereKey($source->id)->update(['status' => 'withdrawn']);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHasErrors('source_enrollment_id');

        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame('withdrawn', $source->fresh()->status);
        $this->assertSame('pending', $promotion->fresh()->status);
        $this->assertNull($promotion->fresh()->target_enrollment_id);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
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

    public function test_a_withdrawn_student_cannot_be_given_a_promotion_request(): void
    {
        $college = $this->makeCollege('PRMWREQ');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['status' => 'withdrawn']);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // TC issuance withdraws the student; no paperwork may be recorded for a
        // promotion that approval would have to refuse.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasErrors([
                'student_id' => 'A withdrawn student cannot receive a new active enrollment through promotion.',
            ]);

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('active', $source->fresh()->status, 'A refused request must not touch the source enrollment.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.requested']);
    }

    public function test_a_withdrawn_source_enrollment_cannot_be_used_in_a_promotion_request(): void
    {
        $college = $this->makeCollege('PRMWSRQ');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'withdrawn']);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasErrors([
                'source_enrollment_id' => 'A withdrawn source enrollment cannot be promoted into a new active enrollment.',
            ]);

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('withdrawn', $source->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.requested']);
    }

    public function test_an_eligible_student_with_an_active_source_enrollment_can_request_a_promotion(): void
    {
        $college = $this->makeCollege('PRMELIG');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['status' => 'active']);
        $source = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-promotions.index'));

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($promotion);
        $this->assertSame('pending', $promotion->status);
        $this->assertSame($source->id, $promotion->source_enrollment_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.requested']);
    }

    public function test_request_and_approval_share_one_eligibility_guard(): void
    {
        $college = $this->makeCollege('PRMGUARD');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        app(TenantContext::class)->set($college);
        $action = app(PromoteStudent::class);
        $payload = $this->payload($student->id, $source->id, $nextYear->id, ['target_program_id' => $program->id]);

        // Refused at REQUEST time while the source is already withdrawn.
        StudentEnrollment::withoutGlobalScopes()->whereKey($source->id)->update(['status' => 'withdrawn']);
        $requestErrors = $this->captureGuardErrors(fn () => $action->request($payload, (int) $college->id, (int) $admin->id));

        // Recorded while eligible, then the source is withdrawn (simulating a
        // TC issued after the request). The SAME guard must refuse at APPROVAL
        // time with an identical error.
        StudentEnrollment::withoutGlobalScopes()->whereKey($source->id)->update(['status' => 'active']);
        $promotion = $action->request($payload, (int) $college->id, (int) $admin->id);
        StudentEnrollment::withoutGlobalScopes()->whereKey($source->id)->update(['status' => 'withdrawn']);
        $approvalErrors = $this->captureGuardErrors(fn () => $action->approve($promotion, (int) $college->id, (int) $admin->id));

        $this->assertSame(['source_enrollment_id'], array_keys($requestErrors));
        $this->assertSame($requestErrors, $approvalErrors, 'Request and approval must reject an ineligible source identically.');

        // The failed approval changed nothing.
        $fresh = $promotion->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->target_enrollment_id);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
    }

    public function test_a_second_pending_request_into_the_same_year_and_program_is_blocked_even_with_a_different_section(): void
    {
        $college = $this->makeCollege('PRMTUPLE');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $sectionB = $this->makeSection($college, $nextYear, $program, 'B');
        $sectionC = $this->makeSection($college, $nextYear, $program, 'C');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionB->id,
            ]))
            ->assertSessionHasNoErrors();

        // Section is NOT part of the enrollment tuple, so a second request into
        // the same year + program could never be approved. It is refused here
        // instead of being recorded as unapprovable paperwork.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionC->id,
            ]))
            ->assertSessionHasErrors('target_academic_year_id');

        $this->assertSame(1, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(
            $sectionB->id,
            StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first()->target_section_id,
            'The first request must be untouched.'
        );
    }

    public function test_a_pending_request_into_a_different_program_is_not_blocked(): void
    {
        $college = $this->makeCollege('PRMPROG');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $from = $this->makeProgram($college, 'BCOM');
        $intoBa = $this->makeProgram($college, 'BA');
        $sectionBa = $this->makeSection($college, $nextYear, $intoBa, 'D');
        $sectionBcom = $this->makeSection($college, $nextYear, $from, 'E');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $from);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $intoBa->id,
                'target_section_id' => $sectionBa->id,
            ]))
            ->assertSessionHasNoErrors();

        // A different program is a different enrollment tuple, so this request
        // is not blocked by the pending one above.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $from->id,
                'target_section_id' => $sectionBcom->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_target_section_is_rejected_when_no_target_program_is_chosen(): void
    {
        $college = $this->makeCollege('PRMSECP');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $sectionB = $this->makeSection($college, $nextYear, $program, 'B');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // Every Section belongs to exactly one Program, so it can never be
        // attached to a program-less target enrollment.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_section_id' => $sectionB->id,
            ]))
            ->assertSessionHasErrors([
                'target_section_id' => StudentService::SECTION_REQUIRES_PROGRAM,
            ]);

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_target_section_is_accepted_when_the_matching_target_program_is_chosen(): void
    {
        $college = $this->makeCollege('PRMSECA');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $sectionB = $this->makeSection($college, $nextYear, $program, 'B');
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
                'target_section_id' => $sectionB->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($promotion);
        $this->assertSame($program->id, $promotion->target_program_id);
        $this->assertSame($sectionB->id, $promotion->target_section_id);
    }

    public function test_approving_from_a_cancelled_source_leaves_the_source_cancelled(): void
    {
        $college = $this->makeCollege('PRMCNCSRC');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program, [
            'status' => 'cancelled',
            'remarks' => 'Cancelled: fee dispute',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHas('success');

        // The recorded reason the enrollment was cancelled must survive: a
        // promotion may not rewrite it into a claim the student completed it.
        $source->refresh();
        $this->assertSame('cancelled', $source->status);
        $this->assertSame('Cancelled: fee dispute', $source->remarks);
        $this->assertNull($source->deleted_at);

        $this->assertSame('approved', $promotion->fresh()->status);
        $this->assertNotNull($promotion->fresh()->target_enrollment_id);
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.updated']);
    }

    public function test_approving_from_a_completed_source_leaves_the_source_completed(): void
    {
        $college = $this->makeCollege('PRMCPLSRC');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'completed']);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHas('success');

        $this->assertSame('completed', $source->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.updated']);
    }

    public function test_a_promotion_request_is_blocked_when_a_transfer_certificate_has_been_issued(): void
    {
        $college = $this->makeCollege('PRMTCISS');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // An ISSUED tc_status is the authoritative marker. The student is still
        // `active` here on purpose: CertificateWorkflow's legacy-TC adoption
        // path can record an issued TC without withdrawing anyone, so the guard
        // must not depend on the withdrawn status alone.
        $this->makeTransfer($college, $student, [
            'enrollment_id' => $source->id,
            'status' => 'approved',
            'tc_status' => 'issued',
            'tc_number' => 'TC-2026-0001',
            'tc_issue_date' => '2026-09-01',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasErrors([
                'student_id' => 'A transfer certificate has already been issued for this student, so a promotion cannot be recorded.',
            ]);

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('active', $source->fresh()->status, 'A refused request must not touch the source enrollment.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.requested']);
    }

    public function test_a_promotion_approval_is_blocked_when_a_tc_is_issued_after_the_request_was_created(): void
    {
        $college = $this->makeCollege('PRMTCAFT');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // Recorded while the student had no TC at all.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();

        // The transfer workflow completes afterwards.
        $this->makeTransfer($college, $student, [
            'enrollment_id' => $source->id,
            'status' => 'approved',
            'tc_status' => 'issued',
            'tc_number' => 'TC-2026-0002',
            'tc_issue_date' => '2026-09-05',
        ]);

        // The SAME shared guard must now refuse at approval time.
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHasErrors([
                'student_id' => 'A transfer certificate has already been issued for this student, so a promotion cannot be recorded.',
            ]);

        $fresh = $promotion->fresh();
        $this->assertSame('pending', $fresh->status, 'A refused approval must not close the request.');
        $this->assertNull($fresh->target_enrollment_id);
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_promotion.approved']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
    }

    public function test_an_unissued_or_cancelled_transfer_does_not_block_a_promotion(): void
    {
        $college = $this->makeCollege('PRMTCNO');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);

        // An approved-but-unissued transfer request: not a TC yet.
        $awaiting = $this->makeStudent($college, ['student_number' => 'STU-TC-AWAIT']);
        $awaitingSource = $this->makeEnrollment($college, $awaiting, $year, $program);
        $this->makeTransfer($college, $awaiting, [
            'enrollment_id' => $awaitingSource->id,
            'status' => 'approved',
            'tc_status' => 'pending',
        ]);

        // A cancelled TC: withdrawn, never issued.
        $cancelled = $this->makeStudent($college, ['student_number' => 'STU-TC-CANC']);
        $cancelledSource = $this->makeEnrollment($college, $cancelled, $year, $program);
        $this->makeTransfer($college, $cancelled, [
            'enrollment_id' => $cancelledSource->id,
            'status' => 'cancelled',
            'tc_status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($awaiting->id, $awaitingSource->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($cancelled->id, $cancelledSource->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_non_transfer_certificate_does_not_block_a_promotion(): void
    {
        $college = $this->makeCollege('PRMBON');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // A fully ISSUED bonafide certificate. Certificates carry their own
        // lifecycle and CertificateType covers seven kinds, so only a
        // StudentTransfer with tc_status = issued may trigger the guard.
        $type = CertificateType::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'Bonafide Certificate',
            'code' => 'BON',
            'builtin_key' => 'bonafide',
        ]);
        Certificate::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'certificate_type_id' => $type->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $source->id,
            'status' => 'issued',
            'number' => 'BON-2026-000001',
            'issued_at' => now(),
        ]);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($promotion);
        $this->assertSame('pending', $promotion->status);
    }

    /**
     * A promotion request sets up a student with one enrollment in $year and an
     * approved-ready pending promotion into $nextYear. Returns the promotion.
     */
    private function makePendingPromotion($college, $admin, $student, $source, $nextYear, $program): StudentPromotion
    {
        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), $this->payload($student->id, $source->id, $nextYear->id, [
                'target_program_id' => $program->id,
            ]))
            ->assertSessionHasNoErrors();

        return StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
    }

    public function test_approving_a_promotion_marks_the_source_year_record_as_promoted(): void
    {
        $college = $this->makeCollege('PRMLEDGOK');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // The student's ledger row for the period being promoted OUT of.
        $record = $this->makeAcademicRecord($college, $student, $year);
        $this->assertSame('not_applicable', $record->promotion_status);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHas('success');

        $fresh = $record->fresh();
        $this->assertSame('promoted', $fresh->promotion_status);
        $this->assertSame('approved', $promotion->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_academic_record.updated',
            'subject_id' => $record->id,
        ]);
    }

    public function test_the_ledger_write_targets_the_source_year_and_never_the_target_year(): void
    {
        $college = $this->makeCollege('PRMLEDGSRC');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $sourceRecord = $this->makeAcademicRecord($college, $student, $year);
        $targetRecord = $this->makeAcademicRecord($college, $student, $nextYear);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        // `promotion_status` is the "progression outcome for the period", so it
        // belongs to the period the student left. The target period is one they
        // are only entering — pre-filling it would collide with the day they
        // are promoted out of it.
        $this->assertSame('promoted', $sourceRecord->fresh()->promotion_status);
        $this->assertSame('not_applicable', $targetRecord->fresh()->promotion_status);
    }

    public function test_approving_preserves_the_other_columns_on_the_ledger(): void
    {
        $college = $this->makeCollege('PRMLEDGKPT');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $record = $this->makeAcademicRecord($college, $student, $year, [
            'academic_status' => 'passed',
            'completion_status' => 'completed',
            'remarks' => 'Operator note',
        ]);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        $fresh = $record->fresh();
        $this->assertSame('promoted', $fresh->promotion_status);
        $this->assertSame('passed', $fresh->academic_status, 'Only promotion_status may change.');
        $this->assertSame('completed', $fresh->completion_status);
        $this->assertSame('Operator note', $fresh->remarks);
    }

    public function test_approving_does_not_overwrite_a_promotion_status_an_operator_set(): void
    {
        $college = $this->makeCollege('PRMLEDGOPR');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // `retained` is operator-entered evidence about the period. A promotion
        // is not evidence against it, so it must survive untouched.
        $record = $this->makeAcademicRecord($college, $student, $year, ['promotion_status' => 'retained']);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        $this->assertSame('retained', $record->fresh()->promotion_status);
        $this->assertSame('approved', $promotion->fresh()->status, 'The promotion itself still succeeds.');
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_academic_record.updated',
            'subject_id' => $record->id,
        ]);
    }

    public function test_approving_leaves_a_term_based_ledger_untouched(): void
    {
        $college = $this->makeCollege('PRMLEDGTRM');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // One row per term. The source enrollment carries no term, so the
        // correct row cannot be identified — guessing, or writing to both,
        // would corrupt a term-based ledger.
        $sem1 = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1', 1);
        $sem2 = $this->makeAcademicTerm($college, $year, 'SEM2', 'Semester 2', 2);
        $first = $this->makeAcademicRecord($college, $student, $year, ['academic_term_id' => $sem1->id]);
        $second = $this->makeAcademicRecord($college, $student, $year, ['academic_term_id' => $sem2->id]);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        $this->assertSame('not_applicable', $first->fresh()->promotion_status);
        $this->assertSame('not_applicable', $second->fresh()->promotion_status);
        $this->assertSame('approved', $promotion->fresh()->status, 'The promotion itself still succeeds.');
        // AUD-005: the skip must not be silent — it is audited so an
        // operator can see the ledger was deliberately left unreconciled.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_promotion.ledger_write_skipped',
            'subject_id' => $promotion->id,
        ]);
    }

    public function test_approving_promotes_the_source_enrollment_linked_record_among_term_records(): void
    {
        $college = $this->makeCollege('PRMLEDGLNK');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // Two term rows for the source year, but exactly one points back at
        // the source enrollment — that row is deterministically the source
        // period's record, so it (and only it) is marked promoted.
        $sem1 = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1', 1);
        $sem2 = $this->makeAcademicTerm($college, $year, 'SEM2', 'Semester 2', 2);
        $linked = $this->makeAcademicRecord($college, $student, $year, ['academic_term_id' => $sem1->id, 'enrollment_id' => $source->id]);
        $unlinked = $this->makeAcademicRecord($college, $student, $year, ['academic_term_id' => $sem2->id]);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        $this->assertSame('promoted', $linked->fresh()->promotion_status);
        $this->assertSame('not_applicable', $unlinked->fresh()->promotion_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_academic_record.updated',
            'subject_id' => $linked->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_promotion.ledger_write_skipped',
            'subject_id' => $promotion->id,
        ]);
    }

    public function test_approving_leaves_term_records_sharing_the_source_enrollment_untouched_and_audits_the_skip(): void
    {
        $college = $this->makeCollege('PRMLEDGSHR');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        // Both term rows link to the same source enrollment, so the link
        // cannot discriminate between terms — writing either (or both)
        // would be a guess.
        $sem1 = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1', 1);
        $sem2 = $this->makeAcademicTerm($college, $year, 'SEM2', 'Semester 2', 2);
        $first = $this->makeAcademicRecord($college, $student, $year, ['academic_term_id' => $sem1->id, 'enrollment_id' => $source->id]);
        $second = $this->makeAcademicRecord($college, $student, $year, ['academic_term_id' => $sem2->id, 'enrollment_id' => $source->id]);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        $this->assertSame('not_applicable', $first->fresh()->promotion_status);
        $this->assertSame('not_applicable', $second->fresh()->promotion_status);
        $this->assertSame('approved', $promotion->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_promotion.ledger_write_skipped',
            'subject_id' => $promotion->id,
        ]);
    }

    public function test_approving_never_creates_a_ledger_row_the_operator_did_not_record(): void
    {
        $college = $this->makeCollege('PRMLEDGNONE');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.approve', $promotion))->assertSessionHas('success');

        // A promotion cannot derive academic_status or completion_status, so it
        // must not fabricate a period record.
        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('approved', $promotion->fresh()->status);
    }

    public function test_cancelling_a_pending_promotion_leaves_the_ledger_untouched(): void
    {
        $college = $this->makeCollege('PRMLEDGCAN');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);
        $record = $this->makeAcademicRecord($college, $student, $year, ['promotion_status' => 'promoted']);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);
        $this->asCollege($college, $admin)->post(route('student-promotions.cancel', $promotion))->assertSessionHas('success');

        // Cancellation is only reachable from `pending`, and `promoted` is
        // written only on approval — which is terminal. So a cancelled
        // promotion never wrote the ledger and must not reset a value an
        // operator (or another approved promotion) put there.
        $this->assertSame('cancelled', $promotion->fresh()->status);
        $this->assertSame('promoted', $record->fresh()->promotion_status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_academic_record.updated',
            'subject_id' => $record->id,
        ]);
    }

    public function test_a_refused_approval_leaves_the_ledger_untouched(): void
    {
        $college = $this->makeCollege('PRMLEDGFAIL');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create', 'student_promotions.approve']);
        $year = $this->makeYear($college, '2026', '2025-26');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $source = $this->makeEnrollment($college, $student, $year, $program);
        $record = $this->makeAcademicRecord($college, $student, $year);

        $promotion = $this->makePendingPromotion($college, $admin, $student, $source, $nextYear, $program);

        // The student becomes ineligible after the request was recorded.
        $this->makeTransfer($college, $student, [
            'enrollment_id' => $source->id,
            'status' => 'approved',
            'tc_status' => 'issued',
            'tc_number' => 'TC-2026-0099',
            'tc_issue_date' => '2026-09-01',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.approve', $promotion))
            ->assertSessionHasErrors('student_id');

        // The refusal happens inside the transaction before any write, so
        // nothing — promotion, enrollment or ledger — may have changed.
        $this->assertSame('pending', $promotion->fresh()->status);
        $this->assertNull($promotion->fresh()->target_enrollment_id);
        $this->assertSame('not_applicable', $record->fresh()->promotion_status);
        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_academic_record.updated',
            'subject_id' => $record->id,
        ]);
    }
}
