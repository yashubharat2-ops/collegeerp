<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Focused validation coverage for enrollments beyond the CRUD management suite.
 *
 * Alongside StudentEnrollmentManagementTest these tests pin the tenant-aware
 * write rules: the student/year/program triple can never be re-pointed at
 * another college (on create or update), an ACTIVE duplicate of the triple is
 * rejected even when reached through status reactivation, and a soft-deleted
 * historical enrollment never blocks a re-enrollment of the same triple.
 */
class StudentEnrollmentValidationTest extends TestCase
{
    use StudentTestHelpers;

    public function test_soft_deleted_enrollment_does_not_block_re_enrollment(): void
    {
        $college = $this->makeCollege('ENVSD');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);

        $payload = [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'active',
        ];

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), $payload)
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($enrollment);

        // Historical row is soft-deleted (never force-deleted) ...
        $enrollment->delete();
        $this->assertNotNull($enrollment->fresh()->deleted_at);

        // ... and the same student/year/program can be enrolled again.
        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-enrollments.index'));

        $all = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->get();
        $this->assertCount(2, $all);
        $this->assertSame(1, $all->whereNull('deleted_at')->count(), 'Exactly one live re-enrollment.');
        $this->assertSame(1, $all->whereNotNull('deleted_at')->count(), 'History is preserved as a soft-deleted row.');
    }

    public function test_update_ignores_cross_college_student_year_program_and_number(): void
    {
        $collegeA = $this->makeCollege('ENVUPA');
        $collegeB = $this->makeCollege('ENVUPB');
        $admin = $this->makeUserWithPermissions($collegeA, ['student_enrollments.view', 'student_enrollments.update']);

        $studentA = $this->makeStudent($collegeA);
        $yearA = $this->makeYear($collegeA, '2026');
        $programA = $this->makeProgram($collegeA);
        $enrollment = $this->makeEnrollment($collegeA, $studentA, $yearA, $programA, ['enrollment_number' => 'ENR-KEEP']);

        // Valid, but foreign, ids from college B plus a spoofed number/college.
        $studentB = $this->makeStudent($collegeB);
        $yearB = $this->makeYear($collegeB, '2026');
        $programB = $this->makeProgram($collegeB);

        $this->asCollege($collegeA, $admin)
            ->put(route('student-enrollments.update', $enrollment), [
                'student_id' => $studentB->id,
                'academic_year_id' => $yearB->id,
                'program_id' => $programB->id,
                'college_id' => $collegeB->id,
                'enrollment_number' => 'ENR-HACKED',
                'status' => 'completed',
                'remarks' => 'Cross-college spoof ignored',
            ], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');

        $enrollment->refresh();
        $this->assertSame($studentA->id, $enrollment->student_id);
        $this->assertSame($yearA->id, $enrollment->academic_year_id);
        $this->assertSame($programA->id, $enrollment->program_id);
        $this->assertSame($collegeA->id, $enrollment->college_id);
        $this->assertSame('ENR-KEEP', $enrollment->enrollment_number);
        $this->assertSame('completed', $enrollment->status);
        $this->assertSame('Cross-college spoof ignored', $enrollment->remarks);

        $this->assertDatabaseMissing('student_enrollments', ['enrollment_number' => 'ENR-HACKED']);
    }

    public function test_reactivating_only_live_enrollment_succeeds_and_blocks_new_active_duplicate(): void
    {
        $college = $this->makeCollege('ENVREA');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);

        $payload = [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'active',
        ];

        // The only live enrollment of this triple can be cancelled ...
        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), $payload)->assertSessionHasNoErrors();
        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'cancelled'], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $enrollment->fresh()->status);

        // ... and reactivated: it is still the only live row, so no duplicate exists.
        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'active'], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');
        $this->assertSame('active', $enrollment->fresh()->status);

        // With the triple active again, a second active enrollment is rejected.
        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), $payload)
            ->assertSessionHasErrors('student_id');

        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }
}
