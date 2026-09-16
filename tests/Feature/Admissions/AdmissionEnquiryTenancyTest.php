<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionEnquiryTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_enquiries_of_active_college(): void
    {
        $collegeA = $this->makeCollege('ISOA');
        $collegeB = $this->makeCollege('ISOB');
        $appA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'Alpha', 'last_name' => 'A', 'status' => 'active']);
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Bravo', 'last_name' => 'B', 'status' => 'active']);
        AdmissionEnquiry::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'applicant_id' => $appA->id, 'enquiry_number' => 'ENQ-A', 'status' => 'new']);
        AdmissionEnquiry::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $appB->id, 'enquiry_number' => 'ENQ-B', 'status' => 'new']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-enquiries.index'))
            ->assertSee('ENQ-A')
            ->assertDontSee('ENQ-B');
    }

    public function test_cross_college_records_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('XSCA');
        $collegeB = $this->makeCollege('XSCB');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Foreign', 'last_name' => 'App', 'status' => 'active']);
        $foreign = AdmissionEnquiry::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $appB->id, 'enquiry_number' => 'ENQ-FRG', 'status' => 'new']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view', 'admission_enquiries.update', 'admission_enquiries.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-enquiries.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('admission-enquiries.update', $foreign), [
            'status' => 'contacted',
        ], ['Referer' => route('admission-enquiries.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('admission-enquiries.destroy', $foreign), [], ['Referer' => route('admission-enquiries.index')])->assertNotFound();

        $this->assertSame('ENQ-FRG', $foreign->fresh()->enquiry_number);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SPYA');
        $collegeB = $this->makeCollege('SPYB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view', 'admission_enquiries.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-enquiries.store'), [
                'college_id' => $collegeB->id,
                'applicant_first_name' => 'Sneaky',
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_enquiries', ['college_id' => $collegeA->id]);
        $this->assertDatabaseMissing('admission_enquiries', ['college_id' => $collegeB->id]);
    }

    public function test_applicant_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('CMPA');
        $collegeB = $this->makeCollege('CMPB');
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'last_name' => 'Applicant', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view', 'admission_enquiries.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-enquiries.store'), [
                'applicant_id' => $applicantB->id,
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasErrors('applicant_id');

        $this->assertSame(0, AdmissionEnquiry::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_academic_year_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('AYTA');
        $collegeB = $this->makeCollege('AYTB');
        $yearB = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'name' => '2026-27',
            'code' => '2026',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view', 'admission_enquiries.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Test',
                'academic_year_id' => $yearB->id,
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasErrors('academic_year_id');
    }

    public function test_program_of_another_college_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('PGMA');
        $collegeB = $this->makeCollege('PGMB');
        $programB = Program::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'name' => 'BSc', 'code' => 'BSC', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view', 'admission_enquiries.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Test',
                'program_id' => $programB->id,
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasErrors('program_id');
    }

    public function test_duplicate_check_never_returns_cross_college_applicants(): void
    {
        $collegeA = $this->makeCollege('DUPA');
        $collegeB = $this->makeCollege('DUPB');
        AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'Same', 'last_name' => 'Phone', 'phone' => '9999999999', 'status' => 'active']);
        AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Other', 'last_name' => 'College', 'phone' => '9999999999', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_enquiries.view']);

        $response = $this->asCollege($collegeA, $adminA)->getJson(route('admission-enquiries.duplicate-check', ['phone' => '9999999999']));
        $response->assertOk();
        $response->assertJsonCount(1, 'duplicates');
        $response->assertJsonPath('duplicates.0.name', fn ($name) => str_contains($name, 'Same'));
    }

    public function test_xss_safe_rendering(): void
    {
        $college = $this->makeCollege('XSSE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.create']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Test',
            'status' => 'active',
        ]);
        AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-XSS',
            'status' => 'new',
        ]);

        $response = $this->asCollege($college, $admin)->get(route('admission-enquiries.index'));
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee(e('<script>alert(1)</script>'), false);
    }
}
