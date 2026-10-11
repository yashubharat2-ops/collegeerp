<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Focused validation coverage for enrollments beyond the CRUD management suite.
 *
 * Alongside StudentEnrollmentManagementTest these tests pin tenant-aware
 * writes and the approved lifecycle rules: one non-soft-deleted row occupies
 * each student/year/program tuple across all statuses (including one shared
 * NULL-program group), terminal enrollments cannot be ordinarily reactivated,
 * and soft-deleted history does not block a new enrollment.
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

    public function test_creation_rejects_every_non_soft_deleted_status_for_the_same_tuple(): void
    {
        $college = $this->makeCollege('ENVSTC');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);

        foreach (StudentEnrollment::STATUSES as $status) {
            $student = $this->makeStudent($college);
            $existing = $this->makeEnrollment($college, $student, $year, $program, ['status' => $status]);

            $this->asCollege($college, $admin)
                ->post(route('student-enrollments.store'), [
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'program_id' => $program->id,
                    'status' => 'active',
                ])
                ->assertSessionHasErrors('student_id');

            $this->assertSame($status, $existing->fresh()->status);
        }

        $this->assertSame(
            count(StudentEnrollment::STATUSES),
            StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count(),
        );
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
    }

    public function test_null_program_enrollments_share_one_duplicate_group(): void
    {
        $college = $this->makeCollege('ENVNUL');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $existing = $this->makeEnrollment($college, $student, $year, null, ['status' => 'completed']);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                // Omitted program_id is the same shared NULL-program group.
                'status' => 'active',
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertNull($existing->fresh()->program_id);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
    }

    public function test_a_different_section_does_not_create_a_second_enrollment_for_the_same_tuple(): void
    {
        $college = $this->makeCollege('ENVSEC');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);
        $sectionA = $this->makeSection($college, $year, $program, 'A');
        $sectionB = $this->makeSection($college, $year, $program, 'B');
        $existing = $this->makeEnrollment($college, $student, $year, $program, [
            'section_id' => $sectionA->id,
            'status' => 'completed',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'section_id' => $sectionB->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertSame($sectionA->id, $existing->fresh()->section_id);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_enrollment.created']);
    }

    public function test_completed_cancelled_and_withdrawn_enrollments_cannot_be_reactivated(): void
    {
        $college = $this->makeCollege('ENVSTR');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);

        foreach (['completed', 'cancelled', 'withdrawn'] as $status) {
            $student = $this->makeStudent($college);
            $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['status' => $status]);

            $this->asCollege($college, $admin)
                ->put(route('student-enrollments.update', $enrollment), ['status' => 'active'], [
                    'Referer' => route('student-enrollments.edit', $enrollment),
                ])
                ->assertSessionHasErrors('status');

            $this->assertSame($status, $enrollment->fresh()->status);
            $this->assertDatabaseMissing('audit_logs', [
                'action' => 'student_enrollment.updated',
                'subject_id' => $enrollment->id,
            ]);
        }
    }

    public function test_a_cancelled_enrollment_cannot_be_reactivated_even_without_another_live_row(): void
    {
        $college = $this->makeCollege('ENVREA');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'cancelled']);

        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'active'], [
                'Referer' => route('student-enrollments.edit', $enrollment),
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('cancelled', $enrollment->fresh()->status);
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_enrollment.updated',
            'subject_id' => $enrollment->id,
        ]);
    }
}
