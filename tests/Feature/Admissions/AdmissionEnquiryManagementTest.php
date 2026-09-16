<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionEnquiry;
use App\Models\AuditLog;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionEnquiryManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_enquiry_with_new_applicant_in_one_transaction(): void
    {
        $college = $this->makeCollege('ENQC');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.create']);
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
            'name' => 'BSc',
            'code' => 'BSC',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Rahul',
                'applicant_last_name' => 'Sharma',
                'applicant_email' => 'rahul@example.test',
                'applicant_phone' => '9999999999',
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'source' => 'website',
                'status' => 'new',
                'remarks' => 'Interested in BSc',
                'enquired_at' => '2026-06-15 10:00:00',
                'next_follow_up_at' => '2026-06-16 10:00:00',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertRedirect(route('admission-enquiries.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_applicants', [
            'college_id' => $college->id,
            'first_name' => 'Rahul',
            'last_name' => 'Sharma',
            'email' => 'rahul@example.test',
        ]);

        $applicant = AdmissionApplicant::withoutGlobalScopes()->firstWhere('email', 'rahul@example.test');
        $this->assertDatabaseHas('admission_enquiries', [
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'source' => 'website',
            'status' => 'new',
        ]);

        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->where('applicant_id', $applicant->id)->first();
        $this->assertNotNull($enquiry->enquiry_number);
        $this->assertStringStartsWith('ENQ-', $enquiry->enquiry_number);
    }

    public function test_create_enquiry_with_existing_applicant_reuses_applicant(): void
    {
        $college = $this->makeCollege('REUSE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.create']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Existing',
            'last_name' => 'Applicant',
            'phone' => '9999999999',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_id' => $applicant->id,
                'source' => 'walk_in',
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, AdmissionApplicant::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseHas('admission_enquiries', [
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
        ]);
    }

    public function test_validation_rejects_missing_fields_and_invalid_relations(): void
    {
        $college = $this->makeCollege('VALE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.create']);

        // Missing first_name when applicant_id null
        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasErrors('applicant_first_name');

        // Invalid status
        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Test',
                'status' => 'invalid_status',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasErrors('status');

        // Invalid dates
        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Test',
                'status' => 'new',
                'enquired_at' => '2026-06-20',
                'next_follow_up_at' => '2026-06-19', // before enquired_at
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasErrors('next_follow_up_at');
    }

    public function test_admin_can_update_enquiry(): void
    {
        $college = $this->makeCollege('UPDE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.update']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Asha',
            'last_name' => 'Patel',
            'status' => 'active',
        ]);
        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-001',
            'status' => 'new',
        ]);

        $this->asCollege($college, $admin)->get(route('admission-enquiries.edit', $enquiry))->assertOk()->assertSee('ENQ-001');

        $this->asCollege($college, $admin)
            ->put(route('admission-enquiries.update', $enquiry), [
                'source' => 'referral',
                'status' => 'contacted',
                'remarks' => 'Called and explained',
            ], ['Referer' => route('admission-enquiries.edit', $enquiry)])
            ->assertSessionHas('success');

        $enquiry->refresh();
        $this->assertSame('contacted', $enquiry->status);
        $this->assertSame('referral', $enquiry->source);
    }

    public function test_soft_delete_hides_enquiry_but_keeps_row(): void
    {
        $college = $this->makeCollege('DELE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.delete']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Delete',
            'last_name' => 'Me',
            'status' => 'active',
        ]);
        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-DEL',
            'status' => 'new',
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('admission-enquiries.destroy', $enquiry), [], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('admission_enquiries', ['id' => $enquiry->id]);
        $this->assertDatabaseHas('admission_enquiries', ['id' => $enquiry->id, 'enquiry_number' => 'ENQ-DEL']);
        $this->asCollege($college, $admin)->get(route('admission-enquiries.index'))->assertDontSee('ENQ-DEL');
    }

    public function test_index_supports_search_status_filter_and_pagination(): void
    {
        $college = $this->makeCollege('IDXE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Search',
            'last_name' => 'Test',
            'status' => 'active',
        ]);
        foreach (range(1, 16) as $i) {
            AdmissionEnquiry::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'applicant_id' => $applicant->id,
                'enquiry_number' => sprintf('ENQ-%03d', $i),
                'status' => $i === 16 ? 'closed' : 'new',
            ]);
        }

        $this->asCollege($college, $admin)->get(route('admission-enquiries.index'))
            ->assertSee('ENQ-001')->assertSee('ENQ-015')->assertDontSee('ENQ-016')
            ->assertSee('Showing 1–15 of 16 enquiries.');

        $this->asCollege($college, $admin)->get(route('admission-enquiries.index', ['page' => 2]))
            ->assertSee('ENQ-016')->assertDontSee('ENQ-001');

        $this->asCollege($college, $admin)->get(route('admission-enquiries.index', ['search' => 'ENQ-007']))
            ->assertSee('ENQ-007')->assertDontSee('ENQ-001');

        $this->asCollege($college, $admin)->get(route('admission-enquiries.index', ['search' => 'Search']))
            ->assertSee('ENQ-001');

        $this->asCollege($college, $admin)->get(route('admission-enquiries.index', ['status' => 'closed']))
            ->assertSee('ENQ-016')->assertDontSee('ENQ-001');
    }

    public function test_enquiry_number_is_unique_per_college_and_generated_server_side(): void
    {
        $college = $this->makeCollege('UNQE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'First',
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Second',
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasNoErrors();

        $numbers = AdmissionEnquiry::withoutGlobalScopes()->where('college_id', $college->id)->pluck('enquiry_number');
        $this->assertCount(2, $numbers);
        $this->assertNotEquals($numbers[0], $numbers[1]);
        foreach ($numbers as $num) {
            $this->assertStringStartsWith('ENQ-', $num);
        }

        // Browser cannot set enquiry_number
        $this->asCollege($college, $admin)
            ->post(route('admission-enquiries.store'), [
                'applicant_first_name' => 'Third',
                'enquiry_number' => 'HACKED-123',
                'status' => 'new',
            ], ['Referer' => route('admission-enquiries.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('admission_enquiries', ['enquiry_number' => 'HACKED-123']);
    }

    public function test_audit_logs_record_enquiry_create_update_delete(): void
    {
        $college = $this->makeCollege('AUDE');
        $admin = $this->makeUserWithPermissions($college, ['admission_enquiries.view', 'admission_enquiries.create', 'admission_enquiries.update', 'admission_enquiries.delete']);

        $this->asCollege($college, $admin)->post(route('admission-enquiries.store'), [
            'applicant_first_name' => 'Audit',
            'status' => 'new',
        ], ['Referer' => route('admission-enquiries.index')])->assertSessionHasNoErrors();

        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->firstWhere('college_id', $college->id);

        $this->asCollege($college, $admin)->put(route('admission-enquiries.update', $enquiry), [
            'status' => 'contacted',
            'source' => 'website',
        ], ['Referer' => route('admission-enquiries.edit', $enquiry)]);

        $this->asCollege($college, $admin)->delete(route('admission-enquiries.destroy', $enquiry), [], ['Referer' => route('admission-enquiries.index')]);

        $base = ['college_id' => $college->id, 'user_id' => $admin->id, 'subject_type' => AdmissionEnquiry::class, 'subject_id' => $enquiry->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_enquiry.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_enquiry.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_enquiry.deleted']);
    }
}
