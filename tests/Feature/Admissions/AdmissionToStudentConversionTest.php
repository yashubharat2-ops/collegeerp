<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionToStudentConversionTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeYear(College $college, string $code = '2026'): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => $code,
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
    }

    private function makeProgram(College $college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeAdmission(College $college, array $overrides = []): Admission
    {
        $year = isset($overrides['academic_year_id'])
            ? AcademicYear::withoutGlobalScopes()->find($overrides['academic_year_id'])
            : $this->makeYear($college, 'Y'.substr(uniqid(), -4));
        $program = isset($overrides['program_id'])
            ? Program::withoutGlobalScopes()->find($overrides['program_id'])
            : $this->makeProgram($college, 'P'.substr(uniqid(), -3));

        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Kiran',
            'middle_name' => 'R',
            'last_name' => 'Mehta',
            'email' => strtolower($college->code).'-'.uniqid().'@example.test',
            'phone' => '9887766554',
            'gender' => 'female',
            'date_of_birth' => '2007-03-21',
            'address' => '12 College Road',
            'status' => 'active',
        ]);

        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'application_number' => 'APP-'.uniqid(),
            'status' => 'admitted',
        ]);

        return Admission::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-'.uniqid(),
            'admission_date' => '2026-07-15',
            'status' => 'active',
        ], $overrides));
    }

    public function test_sidebar_lists_enquiries_before_applicants(): void
    {
        $college = $this->makeCollege('ADNV');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view']);

        $html = $this->asCollege($college, $admin)->get(route('admissions.index'))->assertOk()->getContent();
        $enquiries = strpos($html, 'admission-enquiries.index');
        $applicants = strpos($html, 'admission-applicants.index');
        $this->assertNotFalse($enquiries);
        $this->assertNotFalse($applicants);
        $this->assertLessThan($applicants, $enquiries);
    }

    public function test_list_is_titled_final_admissions_and_offers_convert(): void
    {
        $college = $this->makeCollege('ADTI');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'student_enrollments.create',
        ]);
        $admission = $this->makeAdmission($college);

        $this->asCollege($college, $admin)
            ->get(route('admissions.index'))
            ->assertOk()
            ->assertSee('Final Admissions')
            ->assertDontSee('Final Admissions / Enrollment')
            ->assertSee('Convert to Student')
            ->assertSee(route('admissions.convert.create', $admission), false)
            ->assertSee('data-bulk-selection', false)
            ->assertSee('hidden', false)
            ->assertSee('data-select-row', false);
    }

    public function test_from_admission_form_reuses_student_create_and_prefills(): void
    {
        $college = $this->makeCollege('ADFF');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'student_enrollments.create', 'students.view',
        ]);
        $admission = $this->makeAdmission($college);

        $this->asCollege($college, $admin)
            ->get(route('admissions.convert.create', $admission))
            ->assertOk()
            ->assertSee('New Student from Admission')
            ->assertSee('Kiran')
            ->assertSee('Mehta')
            ->assertSee($admission->applicant->email)
            ->assertSee('name="academic_year_id"', false)
            ->assertSee('name="program_id"', false)
            ->assertSee(route('admissions.convert.store', $admission), false)
            ->assertDontSee(route('students.store'), false);
    }

    public function test_direct_new_student_is_unchanged(): void
    {
        $college = $this->makeCollege('ADNS');
        $admin = $this->makeUserWithPermissions($college, ['students.create', 'students.view']);

        $this->asCollege($college, $admin)
            ->get(route('students.create'))
            ->assertOk()
            ->assertSee('New Student')
            ->assertSee(route('students.store'), false)
            ->assertDontSee('From admission');
    }

    public function test_conversion_creates_student_and_enrollment_in_one_transaction(): void
    {
        $college = $this->makeCollege('ADCV');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'student_enrollments.create', 'students.view',
        ]);
        $admission = $this->makeAdmission($college);

        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), [
                'first_name' => 'Kiran',
                'last_name' => 'Mehta',
                'status' => 'active',
                'email' => $admission->applicant->email,
                'phone' => '9887766554',
                'gender' => 'female',
                'date_of_birth' => '2007-03-21',
                'address_line_1' => '12 College Road',
                'admission_date' => '2026-07-15',
                'academic_year_id' => $admission->academic_year_id,
                'program_id' => $admission->program_id,
                'enrollment_date' => '2026-07-15',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($student);
        $this->assertSame($admission->application_id, $student->admission_application_id);
        $this->assertSame('Kiran', $student->first_name);
        $this->assertSame('2007-03-21', $student->date_of_birth?->format('Y-m-d'));
        $this->assertSame('2026-07-15', $student->admission_date?->format('Y-m-d'));
        $this->assertStringStartsWith('STU-', $student->student_number);

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame($admission->academic_year_id, $enrollment->academic_year_id);
        $this->assertSame($admission->program_id, $enrollment->program_id);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);

        $this->assertDatabaseHas('audit_logs', ['action' => 'student.converted', 'subject_id' => $student->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student.created', 'subject_id' => $student->id]);
    }

    public function test_duplicate_conversion_is_rejected(): void
    {
        $college = $this->makeCollege('ADDUP');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'student_enrollments.create', 'students.view',
        ]);
        $admission = $this->makeAdmission($college);

        $payload = [
            'first_name' => 'Kiran',
            'status' => 'active',
            'academic_year_id' => $admission->academic_year_id,
            'program_id' => $admission->program_id,
        ];

        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), $payload)
            ->assertSessionHas('success');

        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), $payload)
            ->assertSessionHasErrors('admission');

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_cancelled_admission_cannot_convert(): void
    {
        $college = $this->makeCollege('ADCAN');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'students.create']);
        $admission = $this->makeAdmission($college, ['status' => 'cancelled']);

        $this->asCollege($college, $admin)
            ->get(route('admissions.convert.create', $admission))
            ->assertRedirect(route('admissions.index'));

        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), ['first_name' => 'Kiran', 'status' => 'active'])
            ->assertSessionHasErrors('admission');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_conversion_requires_permission_and_stays_on_tenant(): void
    {
        $collegeA = $this->makeCollege('ADTA');
        $collegeB = $this->makeCollege('ADTB');
        $viewer = $this->makeUserWithPermissions($collegeA, ['admissions.view']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admissions.view', 'students.create']);
        $admissionB = $this->makeAdmission($collegeB);

        $this->asCollege($collegeA, $viewer)
            ->get(route('admissions.convert.create', $this->makeAdmission($collegeA)))
            ->assertForbidden();

        $this->asCollege($collegeA, $adminA)
            ->get(route('admissions.convert.create', $admissionB))
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->post(route('admissions.convert.store', $admissionB), ['first_name' => 'X', 'status' => 'active'])
            ->assertNotFound();

        $this->assertSame(0, Student::withoutGlobalScopes()->count());
    }

    public function test_converted_admission_shows_student_and_enrollment_links(): void
    {
        $college = $this->makeCollege('ADLK');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'students.view',
            'student_enrollments.create', 'student_enrollments.view', 'student_enrollments.update',
        ]);
        $admission = $this->makeAdmission($college);

        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), [
                'first_name' => 'Kiran',
                'status' => 'active',
                'academic_year_id' => $admission->academic_year_id,
                'program_id' => $admission->program_id,
            ]);

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->first();

        $this->asCollege($college, $admin)
            ->get(route('admissions.index'))
            ->assertOk()
            ->assertSee('View Student')
            ->assertSee('View Enrollment')
            ->assertSee(route('students.show', $student), false)
            ->assertSee(route('student-enrollments.edit', $enrollment), false)
            ->assertDontSee('Convert to Student');
    }
}
