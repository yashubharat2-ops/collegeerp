<?php

namespace Tests\Feature\Students;

use App\Models\AdmissionApplication;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Tests\TestCase;

class StudentConversionTest extends TestCase
{
    use StudentTestHelpers;

    public function test_approved_application_converts_to_student_with_initial_enrollment(): void
    {
        $college = $this->makeCollege('CAPP');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $application = $this->makeApprovedApplication($college, $year, $program, 'approved');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application))
            ->assertRedirect()
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($student);
        $this->assertStringStartsWith('STU-', $student->student_number);
        $this->assertSame($application->id, $student->admission_application_id);
        $this->assertSame('Admit', $student->first_name);
        $this->assertSame('Candidate', $student->last_name);
        $this->assertSame($application->applicant->email, $student->email);

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($program->id, $enrollment->program_id);
        $this->assertSame('active', $enrollment->status);
    }

    public function test_admitted_application_converts_successfully(): void
    {
        $college = $this->makeCollege('CADM');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'admitted');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Student::withoutGlobalScopes()->where('admission_application_id', $application->id)->first());
    }

    public function test_draft_application_cannot_convert(): void
    {
        $college = $this->makeCollege('CDRAFT');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'draft');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application))
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_rejected_application_cannot_convert(): void
    {
        $college = $this->makeCollege('CREJ');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'rejected');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application))
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_cancelled_application_cannot_convert(): void
    {
        $college = $this->makeCollege('CCAN');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'cancelled');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application))
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_submitted_application_cannot_convert_without_approval(): void
    {
        $college = $this->makeCollege('CSUB');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'submitted');

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $application))
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_conversion_is_idempotent(): void
    {
        $college = $this->makeCollege('CIDEM');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'approved');

        $this->asCollege($college, $admin)->post(route('students.convert', $application))->assertSessionHasNoErrors();
        $this->asCollege($college, $admin)->post(route('students.convert', $application))->assertSessionHasNoErrors();

        $this->assertSame(1, Student::withoutGlobalScopes()->where('admission_application_id', $application->id)->count());
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_cross_tenant_conversion_is_blocked(): void
    {
        $collegeA = $this->makeCollege('CTENA');
        $collegeB = $this->makeCollege('CTENB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.create']);
        $yearB = $this->makeYear($collegeB);
        $applicationB = $this->makeApprovedApplication($collegeB, $yearB, null, 'approved');

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.convert', $applicationB))
            ->assertNotFound();

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    public function test_conversion_requires_student_create_permission(): void
    {
        $college = $this->makeCollege('CPERM');
        $viewer = $this->makeUserWithPermissions($college, []);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'approved');

        $this->asCollege($college, $viewer)
            ->post(route('students.convert', $application))
            ->assertForbidden();

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_conversion_audits_the_student_and_enrollment(): void
    {
        $college = $this->makeCollege('CAUD');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $year = $this->makeYear($college);
        $application = $this->makeApprovedApplication($college, $year, null, 'approved');

        $this->asCollege($college, $admin)->post(route('students.convert', $application))->assertSessionHasNoErrors();

        $student = Student::withoutGlobalScopes()->where('admission_application_id', $application->id)->first();
        // No double-audit: conversion writes a single "student.converted" entry.
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.converted', 'subject_id' => $student->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student.created', 'subject_id' => $student->id]);
    }
}
