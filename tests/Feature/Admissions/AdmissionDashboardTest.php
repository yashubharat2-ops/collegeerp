<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionDashboardTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeYear($college, string $code = '2026'): AcademicYear
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

    private function makeProgram($college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    public function test_dashboard_shows_tenant_scoped_counts(): void
    {
        $collegeA = $this->makeCollege('DSHA');
        $collegeB = $this->makeCollege('DSHB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view']);

        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'A', 'status' => 'active']);
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'B', 'status' => 'active']);

        AdmissionEnquiry::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'applicant_id' => $applicantA->id, 'enquiry_number' => 'ENQ-A', 'status' => 'new']);
        AdmissionEnquiry::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $applicantB->id, 'enquiry_number' => 'ENQ-B', 'status' => 'new']);

        $year = $this->makeYear($collegeA);
        $prog = $this->makeProgram($collegeA);

        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'applicant_id' => $applicantA->id, 'academic_year_id' => $year->id, 'program_id' => $prog->id, 'application_number' => 'APP-A1', 'status' => 'draft']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'applicant_id' => $applicantA->id, 'academic_year_id' => $year->id, 'program_id' => $prog->id, 'application_number' => 'APP-A2', 'status' => 'approved']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'applicant_id' => $applicantB->id, 'application_number' => 'APP-B1', 'status' => 'draft']);

        $type = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'code' => 'ID', 'name' => 'ID Proof', 'status' => 'active', 'max_size_kb' => 5120]);
        AdmissionDocument::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'applicant_id' => $applicantA->id,
            'document_type_id' => $type->id,
            'file_path' => 'admissions/'.$collegeA->id.'/'.$applicantA->id.'/test.pdf',
            'original_filename' => 'test.pdf',
            'file_size' => 1000,
            'verification_status' => 'pending',
        ]);

        $response = $this->asCollege($collegeA, $adminA)->get(route('admission.dashboard'));
        $response->assertOk();
        $response->assertSee('Total Enquiries');
        $response->assertSee('Total Applicants');
        $response->assertSee('Total Applications');
        // Ensure counts are tenant scoped: collegeA has 1 enquiry, 1 applicant, 2 applications
        // We check that page contains counts but not assert exact numbers via view data? Check view data
        $response->assertViewHas('totalEnquiries', 1);
        $response->assertViewHas('totalApplicants', 1);
        $response->assertViewHas('totalApplications', 2);
        $response->assertViewHas('pendingDocs', 1);
    }

    public function test_dashboard_requires_permission(): void
    {
        $college = $this->makeCollege('DSHP');
        $userNoPerm = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $userNoPerm)->get(route('admission.dashboard'))->assertForbidden();
    }

    public function test_dashboard_deterministic_program_and_year_counts(): void
    {
        $college = $this->makeCollege('DSHD');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $college->id, 'first_name' => 'Test', 'status' => 'active']);
        $year1 = $this->makeYear($college, '2026');
        $year2 = $this->makeYear($college, '2027');
        $prog1 = $this->makeProgram($college, 'BSC');
        $prog2 = $this->makeProgram($college, 'MSC');

        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $college->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $year1->id, 'program_id' => $prog1->id, 'application_number' => 'APP-1', 'status' => 'draft']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $college->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $year1->id, 'program_id' => $prog2->id, 'application_number' => 'APP-2', 'status' => 'draft']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id' => $college->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $year2->id, 'program_id' => $prog1->id, 'application_number' => 'APP-3', 'status' => 'approved']);

        $response = $this->asCollege($college, $admin)->get(route('admission.dashboard'));
        $response->assertOk();
        $response->assertViewHas('programWise');
        $response->assertViewHas('yearWise');
    }
}
