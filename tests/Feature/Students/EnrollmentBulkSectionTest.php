<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Services\StudentService;
use App\Models\College;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase D: bulk section assignment for enrollments, and the locked re-read in
 * StudentService::updateEnrollment (P0 C). Assignment is all-or-nothing, tenant
 * validated, and never moves an enrollment to another academic year or program.
 */
class EnrollmentBulkSectionTest extends TestCase
{
    use StudentTestHelpers;

    private function assign(College $college, User $user, array $ids, mixed $sectionId): TestResponse
    {
        return $this->asCollege($college, $user)->post(route('bulk-actions.execute'), [
            'module' => 'enrollments',
            'action' => 'assign_section',
            'ids' => $ids,
            'parameters' => ['section_id' => $sectionId],
        ]);
    }

    private function editor(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
    }

    public function test_assign_section_updates_every_selected_enrollment_and_audits(): void
    {
        $college = $this->makeCollege('SECBK');
        $editor = $this->editor($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'A');
        $one = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program);
        $two = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program);

        $this->assign($college, $editor, [$one->id, $two->id], $section->id)
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, '2 enrollments assigned'));

        $this->assertSame($section->id, StudentEnrollment::withoutGlobalScopes()->find($one->id)->section_id);
        $this->assertSame($section->id, StudentEnrollment::withoutGlobalScopes()->find($two->id)->section_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.updated', 'subject_id' => $one->id]);
    }

    public function test_one_enrollment_from_another_year_blocks_the_whole_batch(): void
    {
        $college = $this->makeCollege('SECYR');
        $editor = $this->editor($college);
        $year = $this->makeYear($college);
        $nextYear = $this->makeNextYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'A');
        $sameYear = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program);
        $otherYear = $this->makeEnrollment($college, $this->makeStudent($college), $nextYear, $program);

        $this->assign($college, $editor, [$sameYear->id, $otherYear->id], $section->id)
            ->assertSessionHasErrors('bulk');

        // All-or-nothing: the matching enrollment was not changed either.
        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->find($sameYear->id)->section_id);
        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->find($otherYear->id)->section_id);
    }

    public function test_a_section_from_a_different_program_blocks_the_batch(): void
    {
        $college = $this->makeCollege('SECPRG');
        $editor = $this->editor($college);
        $year = $this->makeYear($college);
        $programA = $this->makeProgram($college, 'PA');
        $programB = $this->makeProgram($college, 'PB');
        $section = $this->makeSection($college, $year, $programB, 'A');
        $enrollment = $this->makeEnrollment($college, $this->makeStudent($college), $year, $programA);

        $this->assign($college, $editor, [$enrollment->id], $section->id)->assertSessionHasErrors('bulk');

        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->find($enrollment->id)->section_id);
    }

    public function test_a_section_from_another_college_is_rejected_and_nothing_changes(): void
    {
        $college = $this->makeCollege('SECTNA');
        $other = $this->makeCollege('SECTNB');
        $editor = $this->editor($college);
        $otherYear = $this->makeYear($other);
        $otherProgram = $this->makeProgram($other);
        $foreignSection = $this->makeSection($other, $otherYear, $otherProgram, 'Z');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enrollment = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program);

        $this->assign($college, $editor, [$enrollment->id], $foreignSection->id)->assertSessionHasErrors('bulk');

        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->find($enrollment->id)->section_id);
    }

    public function test_missing_or_malformed_section_is_rejected(): void
    {
        $college = $this->makeCollege('SECBAD');
        $editor = $this->editor($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enrollment = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program);

        $this->assign($college, $editor, [$enrollment->id], null)->assertSessionHasErrors('bulk');
        $this->assign($college, $editor, [$enrollment->id], 'abc')->assertSessionHasErrors('bulk');
        $this->assign($college, $editor, [$enrollment->id], '1; DROP TABLE x')->assertSessionHasErrors('bulk');

        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->find($enrollment->id)->section_id);
    }

    public function test_viewer_cannot_assign_sections(): void
    {
        $college = $this->makeCollege('SECVIEW');
        $viewer = $this->makeUserWithPermissions($college, ['student_enrollments.view']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'A');
        $enrollment = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program);

        $this->assign($college, $viewer, [$enrollment->id], $section->id)->assertSessionHasErrors('bulk');

        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->find($enrollment->id)->section_id);
    }

    public function test_updating_from_a_stale_copy_rereads_the_enrollment_under_the_student_lock(): void
    {
        $college = $this->makeCollege('SECSTALE');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        // The student already has a live active enrollment for this year and program.
        $this->makeEnrollment($college, $student, $year, $program, ['status' => 'active']);
        $inactive = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'cancelled']);

        // A stale in-memory copy that still says 'active' (for example, loaded
        // before another request cancelled and reactivated it). The service must
        // judge the reactivation on the committed row, not on this copy.
        $stale = StudentEnrollment::withoutGlobalScopes()->find($inactive->id);
        $stale->status = 'active';

        app(TenantContext::class)->set($college);

        $this->expectException(ValidationException::class);
        app(StudentService::class)->updateEnrollment($stale, ['status' => 'active'], (int) $college->id);
    }

    public function test_the_locked_reread_still_allows_a_legitimate_section_change(): void
    {
        $college = $this->makeCollege('SECLOCK');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'B');
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);

        app(TenantContext::class)->set($college);
        $updated = app(StudentService::class)->updateEnrollment(
            StudentEnrollment::withoutGlobalScopes()->find($enrollment->id),
            ['section_id' => $section->id],
            (int) $college->id,
        );

        $this->assertSame($section->id, $updated->section_id);
        $this->assertNotNull(Student::withoutGlobalScopes()->find($student->id));
    }

    public function test_the_enrollment_list_offers_the_section_selector_and_trigger(): void
    {
        $college = $this->makeCollege('SECLIST');
        $editor = $this->editor($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $this->makeSection($college, $year, $program, 'A');

        $this->asCollege($college, $editor)
            ->get(route('student-enrollments.index'))
            ->assertOk()
            ->assertSee('data-bulk-input="section_id"', false)
            ->assertSee('data-bulk-action="assign_section"', false)
            ->assertSee('data-bulk-inputs="section_id"', false);
    }
}
