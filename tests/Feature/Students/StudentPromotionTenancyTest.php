<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use Tests\TestCase;

/**
 * Tenant isolation for promotions: cross-college ids must 404, and no reference
 * (student, enrollment, target year/program/term/section) may point at another
 * college even when the ids are otherwise valid.
 */
class StudentPromotionTenancyTest extends TestCase
{
    use StudentTestHelpers;

    private function payload(int $studentId, int $sourceEnrollmentId, int $targetYearId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'source_enrollment_id' => $sourceEnrollmentId,
            'target_academic_year_id' => $targetYearId,
        ], $overrides);
    }

    public function test_index_only_shows_promotions_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('PMTA');
        $collegeB = $this->makeCollege('PMTB');
        $yearA = $this->makeYear($collegeA, '2026', '2025-26');
        $nextA = $this->makeNextYear($collegeA, '2027');
        $yearB = $this->makeYear($collegeB, '2026', '2025-26');
        $nextB = $this->makeNextYear($collegeB, '2027');
        $programA = $this->makeProgram($collegeA);
        $programB = $this->makeProgram($collegeB);
        $studentA = $this->makeStudent($collegeA, ['student_number' => 'STU-PMA']);
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-PMB']);
        $sourceA = $this->makeEnrollment($collegeA, $studentA, $yearA, $programA);
        $sourceB = $this->makeEnrollment($collegeB, $studentB, $yearB, $programB);

        StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id, 'student_id' => $studentA->id, 'source_enrollment_id' => $sourceA->id,
            'source_academic_year_id' => $yearA->id, 'target_academic_year_id' => $nextA->id, 'status' => 'pending',
        ]);
        StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'student_id' => $studentB->id, 'source_enrollment_id' => $sourceB->id,
            'source_academic_year_id' => $yearB->id, 'target_academic_year_id' => $nextB->id, 'status' => 'pending',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['student_promotions.view']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('student-promotions.index'))
            ->assertSee('STU-PMA')
            ->assertDontSee('STU-PMB');
    }

    public function test_cross_college_promotion_cannot_be_approved_or_cancelled(): void
    {
        $collegeA = $this->makeCollege('PMXA');
        $collegeB = $this->makeCollege('PMXB');
        $yearB = $this->makeYear($collegeB, '2026', '2025-26');
        $nextB = $this->makeNextYear($collegeB, '2027');
        $programB = $this->makeProgram($collegeB);
        $studentB = $this->makeStudent($collegeB);
        $sourceB = $this->makeEnrollment($collegeB, $studentB, $yearB, $programB);

        $foreign = StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'student_id' => $studentB->id, 'source_enrollment_id' => $sourceB->id,
            'source_academic_year_id' => $yearB->id, 'target_academic_year_id' => $nextB->id, 'status' => 'pending',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['student_promotions.view', 'student_promotions.approve']);

        $this->asCollege($collegeA, $adminA)->post(route('student-promotions.approve', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->post(route('student-promotions.cancel', $foreign))->assertNotFound();

        $this->assertSame('pending', $foreign->fresh()->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    public function test_foreign_student_enrollment_and_year_are_rejected(): void
    {
        $collegeA = $this->makeCollege('PMFA');
        $collegeB = $this->makeCollege('PMFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_promotions.create']);
        $yearA = $this->makeYear($collegeA, '2026', '2025-26');
        $nextA = $this->makeNextYear($collegeA, '2027');
        $programA = $this->makeProgram($collegeA);
        $studentA = $this->makeStudent($collegeA);
        $sourceA = $this->makeEnrollment($collegeA, $studentA, $yearA, $programA);

        $yearB = $this->makeYear($collegeB, '2026', '2025-26');
        $nextB = $this->makeNextYear($collegeB, '2027');
        $programB = $this->makeProgram($collegeB);
        $studentB = $this->makeStudent($collegeB);
        $sourceB = $this->makeEnrollment($collegeB, $studentB, $yearB, $programB);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-promotions.store'), $this->payload($studentB->id, $sourceA->id, $nextA->id))
            ->assertSessionHasErrors('student_id');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-promotions.store'), $this->payload($studentA->id, $sourceB->id, $nextA->id))
            ->assertSessionHasErrors('source_enrollment_id');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-promotions.store'), $this->payload($studentA->id, $sourceA->id, $nextB->id))
            ->assertSessionHasErrors('target_academic_year_id');

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_foreign_section_and_term_are_rejected(): void
    {
        $collegeA = $this->makeCollege('PMFSA');
        $collegeB = $this->makeCollege('PMFSB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_promotions.create']);
        $yearA = $this->makeYear($collegeA, '2026', '2025-26');
        $nextA = $this->makeNextYear($collegeA, '2027');
        $programA = $this->makeProgram($collegeA, 'BCOM');
        $studentA = $this->makeStudent($collegeA);
        $sourceA = $this->makeEnrollment($collegeA, $studentA, $yearA, $programA);

        // College B owns a section/term with the same year and program codes.
        $yearB = $this->makeYear($collegeB, '2026', '2025-26');
        $nextB = $this->makeNextYear($collegeB, '2027');
        $programB = $this->makeProgram($collegeB, 'BCOM');
        $foreignSection = $this->makeSection($collegeB, $nextB, $programB, 'B');
        $foreignTerm = $this->makeAcademicTerm($collegeB, $nextB, 'SEM1', 'Semester 1');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-promotions.store'), $this->payload($studentA->id, $sourceA->id, $nextA->id, [
                'target_program_id' => $programA->id,
                'target_section_id' => $foreignSection->id,
            ]))
            ->assertSessionHasErrors('target_section_id');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-promotions.store'), $this->payload($studentA->id, $sourceA->id, $nextA->id, [
                'target_academic_term_id' => $foreignTerm->id,
            ]))
            ->assertSessionHasErrors('target_academic_term_id');

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_college_id_and_status_from_browser_are_never_trusted(): void
    {
        $collegeA = $this->makeCollege('PMSAFE');
        $collegeB = $this->makeCollege('PMSAFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_promotions.create']);
        $yearA = $this->makeYear($collegeA, '2026', '2025-26');
        $nextA = $this->makeNextYear($collegeA, '2027');
        $programA = $this->makeProgram($collegeA);
        $studentA = $this->makeStudent($collegeA);
        $sourceA = $this->makeEnrollment($collegeA, $studentA, $yearA, $programA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-promotions.store'), $this->payload($studentA->id, $sourceA->id, $nextA->id, [
                'college_id' => $collegeB->id,
                'status' => 'approved',
                'target_enrollment_id' => $sourceA->id,
                'approved_by' => $adminA->id,
            ]))
            ->assertSessionHasNoErrors();

        $promotion = StudentPromotion::withoutGlobalScopes()->where('student_id', $studentA->id)->first();

        $this->assertSame($collegeA->id, $promotion->college_id);
        $this->assertSame('pending', $promotion->status);
        $this->assertNull($promotion->target_enrollment_id);
        $this->assertNull($promotion->approved_by);
        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }
}
