<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionApplicationTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_applications_of_active_college(): void
    {
        $collegeA = $this->makeCollege('TAOA');
        $collegeB = $this->makeCollege('TAOB');
        $appA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'Alpha', 'last_name' => 'A', 'status' => 'active']);
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Bravo', 'last_name' => 'B', 'status' => 'active']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'applicant_id' => $appA->id, 'application_number' => 'APP-A', 'status' => 'draft']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $appB->id, 'application_number' => 'APP-B', 'status' => 'draft']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-applications.index'))
            ->assertSee('APP-A')
            ->assertDontSee('APP-B');
    }

    public function test_cross_college_records_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('TXSA');
        $collegeB = $this->makeCollege('TXSB');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Foreign', 'last_name' => 'App', 'status' => 'active']);
        $yearB = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $programB = Program::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $foreign = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'applicant_id' => $appB->id,
            'academic_year_id' => $yearB->id, 'program_id' => $programB->id,
            'application_number' => 'APP-FRG', 'status' => 'draft',
        ]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.update', 'admission_applications.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-applications.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('admission-applications.update', $foreign), [
            'academic_year_id' => $yearB->id,
            'program_id' => $programB->id,
            'status' => 'approved',
        ], ['Referer' => route('admission-applications.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('admission-applications.destroy', $foreign), [], ['Referer' => route('admission-applications.index')])->assertNotFound();

        $this->assertSame('APP-FRG', $foreign->fresh()->application_number);
        $this->assertSame('draft', $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('TSPA');
        $collegeB = $this->makeCollege('TSPB');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'Sneaky', 'status' => 'active']);
        $year = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $program = Program::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-applications.store'), [
                'college_id' => $collegeB->id,
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_applications', ['college_id' => $collegeA->id]);
        $this->assertDatabaseMissing('admission_applications', ['college_id' => $collegeB->id]);
    }

    public function test_applicant_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('TCMA');
        $collegeB = $this->makeCollege('TCMB');
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'last_name' => 'Applicant', 'status' => 'active']);
        $yearA = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $programA = Program::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicantB->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $programA->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors('applicant_id');

        $this->assertSame(0, AdmissionApplication::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_academic_year_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('TAYA');
        $collegeB = $this->makeCollege('TAYB');
        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'A', 'status' => 'active']);
        $programA = Program::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $yearB = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicantA->id,
                'academic_year_id' => $yearB->id,
                'program_id' => $programA->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame(0, AdmissionApplication::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_program_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('TPGA');
        $collegeB = $this->makeCollege('TPGB');
        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'A', 'status' => 'active']);
        $yearA = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $programB = Program::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicantA->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $programB->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors('program_id');

        $this->assertSame(0, AdmissionApplication::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_enquiry_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('TEQA');
        $collegeB = $this->makeCollege('TEQB');
        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'A', 'status' => 'active']);
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'status' => 'active']);
        $yearA = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id, 'name' => '2026-27', 'code' => '2026',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active',
        ]);
        $programA = Program::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $enquiryB = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'applicant_id' => $applicantB->id,
            'enquiry_number' => 'ENQ-FOREIGN', 'status' => 'new',
        ]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicantA->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $programA->id,
                'enquiry_id' => $enquiryB->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors('enquiry_id');

        $this->assertSame(0, AdmissionApplication::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_cross_college_records_are_not_offered_in_create_form_dropdowns(): void
    {
        $collegeA = $this->makeCollege('TDDA');
        $collegeB = $this->makeCollege('TDDB');
        AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'ForeignApplicant', 'last_name' => 'BX', 'status' => 'active']);
        $appB = AdmissionApplicant::withoutGlobalScopes()->firstWhere('first_name', 'ForeignApplicant');
        AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id, 'applicant_id' => $appB->id,
            'enquiry_number' => 'ENQ-FOREIGN-DROP', 'status' => 'new',
        ]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-applications.create'))
            ->assertOk()
            ->assertDontSee('ForeignApplicant')
            ->assertDontSee('ENQ-FOREIGN-DROP');
    }

    public function test_xss_safe_rendering(): void
    {
        $college = $this->makeCollege('TXSS');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.update']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Test',
            'status' => 'active',
        ]);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'application_number' => 'APP-XSS',
            'status' => 'draft',
            'remarks' => '<img src=x onerror=alert(2)>',
        ]);

        $response = $this->asCollege($college, $admin)->get(route('admission-applications.index'));
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee(e('<script>alert(1)</script>'), false);

        // Remarks render in the edit form and must be escaped there too.
        $edit = $this->asCollege($college, $admin)->get(route('admission-applications.edit', $application));
        $edit->assertOk();
        $edit->assertDontSee('<img src=x onerror=alert(2)>', false);
        $edit->assertSee(e('<img src=x onerror=alert(2)>'), false);
    }
}
