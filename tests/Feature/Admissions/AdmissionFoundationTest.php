<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Foundation tests for Admission domain.
 *
 * Covers tenant isolation, unique constraints per college, soft deletes,
 * relationships, and college_id auto-fill via BelongsToCollege.
 *
 * No controllers/routes yet — this is pure model/foundation verification.
 */
class AdmissionFoundationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_applicant_is_tenant_scoped_and_college_id_auto_filled_from_context(): void
    {
        $collegeA = $this->makeCollege('ADMA');
        $collegeB = $this->makeCollege('ADMB');

        // Simulate TenantContext set for college A — BelongsToCollege should auto-fill college_id.
        $context = app(TenantContext::class);
        $context->set($collegeA);

        $applicant = AdmissionApplicant::create([
            'first_name' => 'Rahul',
            'last_name' => 'Sharma',
            'email' => 'rahul@example.test',
            'phone' => '9999999999',
            'status' => 'active',
        ]);

        $this->assertSame($collegeA->id, $applicant->college_id);
        $this->assertDatabaseHas('admission_applicants', ['id' => $applicant->id, 'college_id' => $collegeA->id]);

        // Scoped query: college A context sees its applicant.
        $this->assertSame(1, AdmissionApplicant::query()->count());
        $this->assertTrue(AdmissionApplicant::query()->whereKey($applicant->id)->exists());

        // Switch context to college B — should not see A's applicant (CollegeScope).
        $context->set($collegeB);
        $this->assertSame(0, AdmissionApplicant::query()->count());
        $this->assertFalse(AdmissionApplicant::query()->whereKey($applicant->id)->exists());

        // Without global scopes, both colleges visible, but we have only one row.
        $this->assertSame(1, AdmissionApplicant::withoutGlobalScopes()->count());
        $this->assertSame(1, AdmissionApplicant::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame(0, AdmissionApplicant::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());

        $context->clear();
    }

    public function test_enquiry_and_application_are_tenant_scoped_and_require_applicant_in_same_college(): void
    {
        $collegeA = $this->makeCollege('ENQA');
        $collegeB = $this->makeCollege('ENQB');

        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'first_name' => 'Asha',
            'last_name' => 'Patel',
            'status' => 'active',
        ]);

        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'first_name' => 'Bina',
            'last_name' => 'Roy',
            'status' => 'active',
        ]);

        $yearA = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'name' => '2026-27',
            'code' => '2026',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $programA = Program::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'name' => 'BSc',
            'code' => 'BSC',
            'status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->set($collegeA);

        $enquiry = AdmissionEnquiry::create([
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearA->id,
            'program_id' => $programA->id,
            'enquiry_number' => 'ENQ-001',
            'source' => 'website',
            'status' => 'new',
        ]);

        $this->assertSame($collegeA->id, $enquiry->college_id);
        $this->assertSame($applicantA->id, $enquiry->applicant_id);

        // Application linked to same applicant, same year/program, and originating enquiry.
        $application = AdmissionApplication::create([
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearA->id,
            'program_id' => $programA->id,
            'enquiry_id' => $enquiry->id,
            'application_number' => 'APP-001',
            'status' => 'draft',
        ]);

        $this->assertSame($collegeA->id, $application->college_id);
        $this->assertSame($enquiry->id, $application->enquiry_id);

        // Tenant isolation: college A sees 1 enquiry, 1 application.
        $this->assertSame(1, AdmissionEnquiry::query()->count());
        $this->assertSame(1, AdmissionApplication::query()->count());

        // Switch to B — sees none.
        $context->set($collegeB);
        $this->assertSame(0, AdmissionEnquiry::query()->count());
        $this->assertSame(0, AdmissionApplication::query()->count());

        // Cross-college applicant_id should not be resolvable via scoped query for college A.
        // The enquiry's applicant relationship is college-scoped? AdmissionApplicant has CollegeScope,
        // so when context is A, applicantB (college B) is not found.
        $context->set($collegeA);
        $this->assertNull(AdmissionApplicant::query()->find($applicantB->id));

        // But applicantA is found.
        $this->assertNotNull(AdmissionApplicant::query()->find($applicantA->id));

        $context->clear();
    }

    public function test_same_enquiry_and_application_numbers_allowed_in_different_colleges_but_not_same_college(): void
    {
        $collegeA = $this->makeCollege('UNQA');
        $collegeB = $this->makeCollege('UNQB');

        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'first_name' => 'Uni',
            'last_name' => 'A',
            'status' => 'active',
        ]);
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'first_name' => 'Uni',
            'last_name' => 'B',
            'status' => 'active',
        ]);

        // Same enquiry_number in different colleges is allowed.
        AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'applicant_id' => $applicantA->id,
            'enquiry_number' => 'ENQ-DUP',
            'status' => 'new',
        ]);
        AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'applicant_id' => $applicantB->id,
            'enquiry_number' => 'ENQ-DUP',
            'status' => 'new',
        ]);

        $this->assertSame(2, AdmissionEnquiry::withoutGlobalScopes()->where('enquiry_number', 'ENQ-DUP')->count());

        // Same application_number in different colleges allowed.
        AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'applicant_id' => $applicantA->id,
            'application_number' => 'APP-DUP',
            'status' => 'draft',
        ]);
        AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'applicant_id' => $applicantB->id,
            'application_number' => 'APP-DUP',
            'status' => 'draft',
        ]);

        $this->assertSame(2, AdmissionApplication::withoutGlobalScopes()->where('application_number', 'APP-DUP')->count());

        // Duplicate within same college should be blocked by unique constraint.
        $this->expectException(\Illuminate\Database\QueryException::class);
        AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'applicant_id' => $applicantA->id,
            'enquiry_number' => 'ENQ-DUP',
            'status' => 'new',
        ]);
    }

    public function test_soft_delete_preserves_row_and_hides_from_scoped_queries(): void
    {
        $college = $this->makeCollege('SOFT');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Soft',
            'last_name' => 'Delete',
            'status' => 'active',
        ]);

        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-SOFT',
            'status' => 'new',
        ]);

        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_id' => $enquiry->id,
            'application_number' => 'APP-SOFT',
            'status' => 'draft',
        ]);

        $context = app(TenantContext::class);
        $context->set($college);

        // Soft delete applicant — row preserved, but hidden from scoped query.
        $applicant->delete();
        $this->assertSoftDeleted('admission_applicants', ['id' => $applicant->id]);
        $this->assertDatabaseHas('admission_applicants', ['id' => $applicant->id, 'first_name' => 'Soft']);
        $this->assertSame(0, AdmissionApplicant::query()->count());

        // Enquiry and application still exist (not cascade soft deleted automatically).
        $this->assertSame(1, AdmissionEnquiry::query()->count());
        $this->assertSame(1, AdmissionApplication::query()->count());

        // Soft delete enquiry.
        $enquiry->delete();
        $this->assertSoftDeleted('admission_enquiries', ['id' => $enquiry->id]);
        $this->assertSame(0, AdmissionEnquiry::query()->count());
        $this->assertSame(1, AdmissionApplication::query()->count());

        // Soft delete application.
        $application->delete();
        $this->assertSoftDeleted('admission_applications', ['id' => $application->id]);
        $this->assertSame(0, AdmissionApplication::query()->count());

        // Without global scopes + with trashed, all still present.
        $this->assertSame(1, AdmissionApplicant::withoutGlobalScopes()->withTrashed()->where('college_id', $college->id)->count());
        $this->assertSame(1, AdmissionEnquiry::withoutGlobalScopes()->withTrashed()->where('college_id', $college->id)->count());
        $this->assertSame(1, AdmissionApplication::withoutGlobalScopes()->withTrashed()->where('college_id', $college->id)->count());

        $context->clear();
    }

    public function test_applicant_can_have_multiple_applications_across_years_and_programs(): void
    {
        $college = $this->makeCollege('MULT');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Multi',
            'last_name' => 'App',
            'status' => 'active',
        ]);

        $year1 = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => '2026',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $year2 = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2027-28',
            'code' => '2027',
            'starts_on' => '2027-06-01',
            'ends_on' => '2028-05-31',
            'status' => 'inactive',
        ]);

        $program1 = Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => 'BSC',
            'status' => 'active',
        ]);
        $program2 = Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'MSc',
            'code' => 'MSC',
            'status' => 'active',
        ]);

        AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year1->id,
            'program_id' => $program1->id,
            'application_number' => 'APP-001',
            'status' => 'submitted',
        ]);
        AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year1->id,
            'program_id' => $program2->id,
            'application_number' => 'APP-002',
            'status' => 'draft',
        ]);
        AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year2->id,
            'program_id' => $program1->id,
            'application_number' => 'APP-003',
            'status' => 'draft',
        ]);

        $context = app(TenantContext::class);
        $context->set($college);

        $this->assertSame(3, AdmissionApplication::query()->where('applicant_id', $applicant->id)->count());
        $this->assertSame(2, AdmissionApplication::query()->where('applicant_id', $applicant->id)->where('academic_year_id', $year1->id)->count());

        // Verify indexes for common queries work (no exception, query returns).
        $this->assertSame(1, AdmissionApplication::query()->where('academic_year_id', $year1->id)->where('program_id', $program1->id)->count());
        $this->assertSame(1, AdmissionApplication::query()->where('academic_year_id', $year2->id)->count());

        $context->clear();
    }

    public function test_no_duplicate_person_data_in_enquiry_and_application(): void
    {
        // Ensure enquiry and application tables do NOT have person columns like first_name, email, phone.
        // This test documents the design decision to avoid duplication.
        $enquiryColumns = \Illuminate\Support\Facades\Schema::getColumnListing('admission_enquiries');
        $applicationColumns = \Illuminate\Support\Facades\Schema::getColumnListing('admission_applications');

        $forbidden = ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'address'];

        foreach ($forbidden as $col) {
            $this->assertNotContains($col, $enquiryColumns, "admission_enquiries should not have $col — person data lives in admission_applicants");
            $this->assertNotContains($col, $applicationColumns, "admission_applications should not have $col — person data lives in admission_applicants");
        }

        // Applicant table SHOULD have them.
        $applicantColumns = \Illuminate\Support\Facades\Schema::getColumnListing('admission_applicants');
        $this->assertContains('first_name', $applicantColumns);
        $this->assertContains('last_name', $applicantColumns);
        $this->assertContains('email', $applicantColumns);
        $this->assertContains('phone', $applicantColumns);
    }

    public function test_academic_year_and_program_relationships_are_nullable_and_preserve_history(): void
    {
        $college = $this->makeCollege('HIST');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Hist',
            'last_name' => 'Test',
            'status' => 'active',
        ]);

        $year = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => '2026',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $program = Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BCom',
            'code' => 'BCOM',
            'status' => 'active',
        ]);

        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'enquiry_number' => 'ENQ-HIST',
            'status' => 'new',
        ]);

        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'enquiry_id' => $enquiry->id,
            'application_number' => 'APP-HIST',
            'status' => 'draft',
        ]);

        // Simulate academic year hard delete (should nullify, not delete enquiry/application).
        // Note: AcademicYear uses softDeletes, so hard delete is forceDelete.
        $year->forceDelete();

        $enquiry->refresh();
        $application->refresh();

        $this->assertNull($enquiry->academic_year_id, 'Enquiry academic_year_id should be nullOnDelete to preserve history');
        $this->assertNull($application->academic_year_id, 'Application academic_year_id should be nullOnDelete to preserve history');
        $this->assertDatabaseHas('admission_enquiries', ['id' => $enquiry->id, 'enquiry_number' => 'ENQ-HIST']);
        $this->assertDatabaseHas('admission_applications', ['id' => $application->id, 'application_number' => 'APP-HIST']);

        // Program nullOnDelete similarly.
        $program->forceDelete();
        $enquiry->refresh();
        $application->refresh();
        $this->assertNull($enquiry->program_id);
        $this->assertNull($application->program_id);
        $this->assertDatabaseHas('admission_enquiries', ['id' => $enquiry->id]);
        $this->assertDatabaseHas('admission_applications', ['id' => $application->id]);
    }
}
