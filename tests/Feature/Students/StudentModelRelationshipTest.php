<?php

namespace Tests\Feature\Students;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

/**
 * Focused model/relationship tests for the Student Management foundation.
 *
 * Verifies the relationship wiring (belongsTo/hasMany), date casting, soft
 * deletes, and that the tenant architecture (BelongsToCollege + CollegeScope)
 * is honoured by both models.
 */
class StudentModelRelationshipTest extends TestCase
{
    use StudentTestHelpers;

    public function test_student_relationships_resolve_college_application_and_enrollments(): void
    {
        $college = $this->makeCollege('RLSR');
        app(TenantContext::class)->set($college);

        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $application = $this->makeApprovedApplication($college, $year, $program);
        $student = $this->makeStudent($college, ['admission_application_id' => $application->id]);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);

        $fresh = Student::query()->findOrFail($student->id);

        $this->assertInstanceOf(BelongsTo::class, $fresh->college());
        $this->assertSame($college->id, $fresh->college->id);

        $this->assertInstanceOf(BelongsTo::class, $fresh->admissionApplication());
        $this->assertSame($application->id, $fresh->admissionApplication->id);
        $this->assertSame($application->applicant->id, $fresh->admissionApplication->applicant->id);

        $this->assertInstanceOf(HasMany::class, $fresh->enrollments());
        $this->assertCount(1, $fresh->enrollments);
        $this->assertSame($enrollment->id, $fresh->enrollments->first()->id);
    }

    public function test_student_admission_application_link_is_nullable(): void
    {
        $college = $this->makeCollege('RLSN');
        app(TenantContext::class)->set($college);

        $student = $this->makeStudent($college, ['admission_application_id' => null]);

        $this->assertNull($student->admission_application_id);
        $this->assertNull($student->admissionApplication);
    }

    public function test_enrollment_relationships_resolve_student_college_year_and_program(): void
    {
        $college = $this->makeCollege('RLER');
        app(TenantContext::class)->set($college);

        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);

        $fresh = StudentEnrollment::query()->findOrFail($enrollment->id);

        $this->assertInstanceOf(BelongsTo::class, $fresh->student());
        $this->assertSame($student->id, $fresh->student->id);

        $this->assertInstanceOf(BelongsTo::class, $fresh->college());
        $this->assertSame($college->id, $fresh->college->id);

        $this->assertInstanceOf(BelongsTo::class, $fresh->academicYear());
        $this->assertSame($year->id, $fresh->academicYear->id);

        $this->assertInstanceOf(BelongsTo::class, $fresh->program());
        $this->assertSame($program->id, $fresh->program->id);
    }

    public function test_enrollment_program_relationship_is_nullable(): void
    {
        $college = $this->makeCollege('RLEN');
        app(TenantContext::class)->set($college);

        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, null);

        $fresh = StudentEnrollment::query()->findOrFail($enrollment->id);

        $this->assertNull($fresh->program_id);
        $this->assertNull($fresh->program);
    }

    public function test_date_attributes_are_casted_to_carbon(): void
    {
        $college = $this->makeCollege('RLDT');
        app(TenantContext::class)->set($college);

        $year = $this->makeYear($college);
        $student = $this->makeStudent($college, [
            'date_of_birth' => '2008-06-15',
            'admission_date' => '2026-09-18',
        ]);
        $enrollment = $this->makeEnrollment($college, $student, $year, null, ['enrollment_date' => '2026-01-05']);

        $freshStudent = Student::query()->findOrFail($student->id);
        $this->assertInstanceOf(CarbonInterface::class, $freshStudent->date_of_birth);
        $this->assertSame('2008-06-15', $freshStudent->date_of_birth->format('Y-m-d'));
        $this->assertInstanceOf(CarbonInterface::class, $freshStudent->admission_date);
        $this->assertSame('2026-09-18', $freshStudent->admission_date->format('Y-m-d'));

        $freshEnrollment = StudentEnrollment::query()->findOrFail($enrollment->id);
        $this->assertInstanceOf(CarbonInterface::class, $freshEnrollment->enrollment_date);
        $this->assertSame('2026-01-05', $freshEnrollment->enrollment_date->format('Y-m-d'));
    }

    public function test_student_uses_soft_deletes(): void
    {
        $college = $this->makeCollege('RLSD');
        app(TenantContext::class)->set($college);

        $student = $this->makeStudent($college, ['student_number' => 'STU-SD']);
        $student->delete();

        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertNull(Student::query()->find($student->id));
        $this->assertNotNull(Student::withTrashed()->find($student->id));
    }

    public function test_enrollment_uses_soft_deletes(): void
    {
        $college = $this->makeCollege('RLED');
        app(TenantContext::class)->set($college);

        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-SD']);
        $enrollment->delete();

        $this->assertSoftDeleted('student_enrollments', ['id' => $enrollment->id]);
        $this->assertNull(StudentEnrollment::query()->find($enrollment->id));
        $this->assertNotNull(StudentEnrollment::withTrashed()->find($enrollment->id));
    }

    public function test_college_scope_is_applied_via_belongs_to_college(): void
    {
        $collegeA = $this->makeCollege('RLSA');
        $collegeB = $this->makeCollege('RLSB');

        $this->makeStudent($collegeA, ['student_number' => 'STU-SA']);
        $this->makeStudent($collegeB, ['student_number' => 'STU-SB']);

        // Without a tenant context the CollegeScope returns no rows.
        app(TenantContext::class)->clear();
        $this->assertSame(0, Student::query()->count());
        $this->assertSame(0, StudentEnrollment::query()->count());

        // With a tenant context only that college's rows are visible.
        app(TenantContext::class)->set($collegeA);
        $visible = Student::query()->pluck('student_number')->all();
        $this->assertContains('STU-SA', $visible);
        $this->assertNotContains('STU-SB', $visible);
    }

    public function test_belongs_to_college_auto_fills_college_id_from_tenant_context(): void
    {
        $college = $this->makeCollege('RLAF');
        app(TenantContext::class)->set($college);

        $student = Student::create([
            'student_number' => 'STU-AUTO',
            'first_name' => 'Auto',
            'status' => 'active',
        ]);

        $this->assertSame($college->id, $student->college_id);
    }
}
