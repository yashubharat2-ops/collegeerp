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

        $admission = Admission::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-'.uniqid(),
            'admission_date' => '2026-07-15',
            'status' => 'active',
        ], $overrides));

        // The returned admission carries its valid application/applicant chain
        // in memory: lazy-loading $admission->applicant inside a test body runs
        // the tenant scope, which matches no rows until a request has pinned
        // the active college (the POST payload is built before any request).
        $admission->setRelation('application', $application);
        $admission->setRelation('applicant', $applicant);

        return $admission;
    }

    public function test_sidebar_lists_enquiries_before_applicants(): void
    {
        $college = $this->makeCollege('ADNV');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view']);

        // The sidebar renders real hrefs (route URIs), never route names, so
        // the order is asserted on the rendered links — the same convention
        // the Transport navigation tests use for sidebar order.
        $this->asCollege($college, $admin)
            ->get(route('admissions.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('admission-enquiries.index').'"',
                'href="'.route('admission-applicants.index').'"',
            ], false);
    }

    public function test_list_is_titled_final_admissions_and_offers_convert(): void
    {
        $college = $this->makeCollege('ADTI');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'admissions.update',
        ]);
        $admission = $this->makeAdmission($college, ['status' => 'completed']);

        $this->asCollege($college, $admin)
            ->get(route('admissions.index'))
            ->assertOk()
            ->assertSee('Final Admissions')
            ->assertDontSee('Final Admissions / Enrollment')
            ->assertSee('Convert to Student')
            ->assertSee(route('students.convert', $admission->application_id), false)
            ->assertSee('data-bulk-action="export"', false)
            ->assertSee('data-bulk-action="complete"', false)
            ->assertSee('data-bulk-action="cancel"', false);
    }

    public function test_from_admission_form_reuses_student_create_and_prefills(): void
    {
        $college = $this->makeCollege('ADFF');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'student_enrollments.create', 'students.view',
        ]);
        $admission = $this->makeAdmission($college);

        $response = $this->asCollege($college, $admin)
            ->get(route('admissions.convert.create', $admission))
            ->assertOk();

        $response
            ->assertSee('New Student from Admission')
            ->assertSee('Kiran')
            ->assertSee('Mehta')
            ->assertSee($admission->applicant->email)
            ->assertSee('name="academic_year_id"', false)
            ->assertSee('name="program_id"', false);

        // The conversion form under test is the <form> that posts to the
        // conversion endpoint — matched, never assumed. The action assertions
        // are scoped to that form's markup: the page chrome (sidebar, header)
        // links to the Students module, and the form's own Cancel button links
        // to the students INDEX (the same URI as route('students.store')), so
        // a page-wide or raw-substring check would false-positive on
        // navigation instead of the form under test.
        $html = $response->getContent();
        $matched = preg_match(
            '/<form\b[^>]*\baction="'.preg_quote(route('admissions.convert.store', $admission), '/').'"[^>]*>.*?<\/form>/s',
            $html,
            $matches
        );
        $this->assertSame(1, $matched, 'The conversion form must post to the conversion endpoint.');
        $formHtml = $matches[0];

        $this->assertStringNotContainsString(
            'action="'.route('students.store').'"',
            $formHtml,
            'The conversion form must not carry the direct Student create/store form action.'
        );
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

    public function test_duplicate_conversion_is_idempotent_and_writes_nothing(): void
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

        // The second submission returns the existing student: nothing new is written,
        // and the (different) profile input is not applied.
        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), array_merge($payload, ['first_name' => 'Changed']))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'already been converted')
                && str_contains($message, 'No new student was created'));

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('Kiran', Student::withoutGlobalScopes()->where('college_id', $college->id)->first()->first_name);
    }

    public function test_conversion_requires_enrollment_create_permission(): void
    {
        $college = $this->makeCollege('ADENR');
        // Student permission only: the conversion would also create an enrollment.
        $studentOnly = $this->makeUserWithPermissions($college, ['admissions.view', 'students.create']);
        $admission = $this->makeAdmission($college);

        $this->asCollege($college, $studentOnly)
            ->get(route('admissions.convert.create', $admission))
            ->assertForbidden();

        $this->asCollege($college, $studentOnly)
            ->post(route('admissions.convert.store', $admission), [
                'first_name' => 'Kiran',
                'status' => 'active',
                'academic_year_id' => $admission->academic_year_id,
                'program_id' => $admission->program_id,
            ])
            ->assertForbidden();

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_admission_after_application_conversion_returns_the_existing_student(): void
    {
        $college = $this->makeCollege('ADXP1');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'students.create', 'student_enrollments.create', 'students.view',
        ]);
        $admission = $this->makeAdmission($college);

        // The application route converts first (the admission's application is 'admitted').
        $this->asCollege($college, $admin)
            ->post(route('students.convert', $admission->application_id))
            ->assertSessionHas('success');

        // The admission route must not create a second student for the same application.
        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), [
                'first_name' => 'Kiran',
                'status' => 'active',
                'academic_year_id' => $admission->academic_year_id,
                'program_id' => $admission->program_id,
            ])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'No new student was created'));

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_cancel_is_blocked_while_a_live_student_exists_for_the_admission(): void
    {
        $college = $this->makeCollege('ADCXL');
        $admin = $this->makeUserWithPermissions($college, [
            'admissions.view', 'admissions.update', 'students.create', 'student_enrollments.create', 'students.view',
        ]);
        $admission = $this->makeAdmission($college);

        $this->asCollege($college, $admin)
            ->post(route('admissions.convert.store', $admission), [
                'first_name' => 'Kiran',
                'status' => 'active',
                'academic_year_id' => $admission->academic_year_id,
                'program_id' => $admission->program_id,
            ])
            ->assertSessionHas('success');

        $this->asCollege($college, $admin)
            ->post(route('admissions.cancel', $admission), ['remarks' => 'Withdrawn'])
            ->assertSessionHasErrors('admission');

        // Nothing changed: the admission stays active and the student stays live.
        $this->assertSame('active', Admission::withoutGlobalScopes()->find($admission->id)->status);
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
    }

    public function test_cancelled_admission_cannot_convert(): void
    {
        $college = $this->makeCollege('ADCAN');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'students.create', 'student_enrollments.create']);
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
        $adminA = $this->makeUserWithPermissions($collegeA, ['admissions.view', 'students.create', 'student_enrollments.create']);
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
            'student_enrollments.create', 'student_enrollments.view',
        ]);
        $admission = $this->makeAdmission($college, ['status' => 'completed']);

        $this->asCollege($college, $admin)
            ->post(route('students.convert', $admission->application_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($student);
        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->first();
        $this->assertNotNull($enrollment);

        $this->asCollege($college, $admin)
            ->get(route('admissions.index'))
            ->assertOk()
            ->assertSee('View Student')
            ->assertSee('View Enrollment')
            ->assertSee(route('students.show', $student), false)
            ->assertSee(route('student-enrollments.index', ['student_id' => $student->id]), false)
            ->assertDontSee('Convert to Student');
    }

    public function test_completed_admission_converts_through_existing_application_route(): void
    {
        $college = $this->makeCollege('ADCM');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'students.create']);
        $admission = $this->makeAdmission($college, ['status' => 'completed']);

        $this->asCollege($college, $admin)
            ->from(route('admissions.index'))
            ->post(route('students.convert', $admission->application_id))
            ->assertRedirect();

        $this->assertSame(1, Student::withoutGlobalScopes()->where('admission_application_id', $admission->application_id)->count());
    }
}
