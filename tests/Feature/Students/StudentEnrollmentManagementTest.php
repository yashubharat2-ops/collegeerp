<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

class StudentEnrollmentManagementTest extends TestCase
{
    use StudentTestHelpers;

    public function test_admin_can_enroll_student_into_academic_year_with_server_generated_number(): void
    {
        $college = $this->makeCollege('EMMC');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertRedirect(route('student-enrollments.index'))
            ->assertSessionHas('success');

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($program->id, $enrollment->program_id);
        $this->assertSame($college->id, $enrollment->college_id);
    }

    public function test_validation_requires_student_and_academic_year(): void
    {
        $college = $this->makeCollege('EMVL');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.create']);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), ['status' => 'active'])
            ->assertSessionHasErrors(['student_id', 'academic_year_id']);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => 999999,
                'academic_year_id' => 999999,
                'status' => 'invalid',
            ])
            ->assertSessionHasErrors(['student_id', 'academic_year_id', 'status']);
    }

    public function test_enrollment_number_and_college_id_spoofing_are_rejected(): void
    {
        $collegeA = $this->makeCollege('EMSNA');
        $collegeB = $this->makeCollege('EMSNB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $student = $this->makeStudent($collegeA);
        $year = $this->makeYear($collegeA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'enrollment_number' => 'HACKED-ENR',
                'college_id' => $collegeB->id,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeB->id)->first();
        $this->assertNull($enrollment);
        $this->assertDatabaseMissing('student_enrollments', ['enrollment_number' => 'HACKED-ENR']);
    }

    public function test_student_of_another_college_cannot_be_enrolled(): void
    {
        $collegeA = $this->makeCollege('EMSCA');
        $collegeB = $this->makeCollege('EMSCB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentB = $this->makeStudent($collegeB);
        $yearA = $this->makeYear($collegeA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentB->id,
                'academic_year_id' => $yearA->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_academic_year_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('EMAYA');
        $collegeB = $this->makeCollege('EMAYB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentA = $this->makeStudent($collegeA);
        $yearB = $this->makeYear($collegeB);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentA->id,
                'academic_year_id' => $yearB->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_program_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('EMPPA');
        $collegeB = $this->makeCollege('EMPPB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentA = $this->makeStudent($collegeA);
        $yearA = $this->makeYear($collegeA);
        $programB = $this->makeProgram($collegeB);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentA->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $programB->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('program_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_duplicate_active_enrollment_for_same_student_year_program_is_prevented(): void
    {
        $college = $this->makeCollege('EMDUP');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])->assertSessionHasErrors('student_id');

        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_admin_can_update_enrollment_and_student_year_program_are_immutable(): void
    {
        $college = $this->makeCollege('EMUP');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $student = $this->makeStudent($college);
        $otherStudent = $this->makeStudent($college, ['first_name' => 'Other']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['enrollment_number' => 'ENR-FIXED']);

        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), [
                'student_id' => $otherStudent->id,
                'academic_year_id' => 999999,
                'program_id' => 999999,
                'enrollment_number' => 'HACKED',
                'status' => 'cancelled',
                'remarks' => 'Moved',
            ], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');

        $enrollment->refresh();
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($program->id, $enrollment->program_id);
        $this->assertSame('ENR-FIXED', $enrollment->enrollment_number);
        $this->assertSame('cancelled', $enrollment->status);
        $this->assertSame('Moved', $enrollment->remarks);
    }

    public function test_history_is_preserved_when_student_moves_to_new_academic_year(): void
    {
        $college = $this->makeCollege('EMHIS');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year1 = $this->makeYear($college, '2026');
        $year2 = $this->makeYear($college, '2027');
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year1->id,
            'program_id' => $program->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        // Move on: the previous enrollment is cancelled (soft-deleted) but its
        // row (and number) is retained, and the new one is created.
        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year2->id,
            'program_id' => $program->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $all = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->get();
        $this->assertCount(2, $all);
        $this->assertSame(1, $all->where('academic_year_id', $year1->id)->count());
        $this->assertSame(1, $all->where('academic_year_id', $year2->id)->count());
    }

    public function test_cross_college_enrollment_is_isolated(): void
    {
        $collegeA = $this->makeCollege('EMTNA');
        $collegeB = $this->makeCollege('EMTNB');
        $studentA = $this->makeStudent($collegeA);
        $studentB = $this->makeStudent($collegeB);
        $yearA = $this->makeYear($collegeA);
        $yearB = $this->makeYear($collegeB);
        $enrollmentA = $this->makeEnrollment($collegeA, $studentA, $yearA, null, ['enrollment_number' => 'ENR-A']);
        $enrollmentB = $this->makeEnrollment($collegeB, $studentB, $yearB, null, ['enrollment_number' => 'ENR-FRG']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.view', 'student_enrollments.update', 'student_enrollments.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('student-enrollments.index'))->assertSee('ENR-A')->assertDontSee('ENR-FRG');

        $this->asCollege($collegeA, $adminA)->get(route('student-enrollments.edit', $enrollmentB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->put(route('student-enrollments.update', $enrollmentB), ['status' => 'cancelled'], ['Referer' => route('student-enrollments.index')])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('student-enrollments.destroy', $enrollmentB), [], ['Referer' => route('student-enrollments.index')])->assertNotFound();

        $this->assertSame('ENR-FRG', $enrollmentB->fresh()->enrollment_number);
        $this->assertNull($enrollmentB->fresh()->deleted_at);
    }

    public function test_audit_logs_record_enrollment_create_update_delete(): void
    {
        $college = $this->makeCollege('EMAUD');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update', 'student_enrollments.delete']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->first();
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.created', 'subject_id' => $enrollment->id]);

        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'completed'], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.updated', 'subject_id' => $enrollment->id]);

        $this->asCollege($college, $admin)->delete(route('student-enrollments.destroy', $enrollment), [], ['Referer' => route('student-enrollments.index')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.deleted', 'subject_id' => $enrollment->id]);
    }
}
