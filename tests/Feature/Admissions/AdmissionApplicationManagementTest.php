<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\College;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionApplicationManagementTest extends TestCase
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

    private function makeApplicant(College $college, array $overrides = []): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'first_name' => 'Rahul',
            'last_name' => 'Sharma',
            'status' => 'active',
        ], $overrides));
    }

    public function test_college_admin_can_create_application_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('APLC');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-001',
            'status' => 'new',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'enquiry_id' => $enquiry->id,
                'status' => 'draft',
                'remarks' => 'First application',
            ], ['Referer' => route('admission-applications.index')])
            ->assertRedirect(route('admission-applications.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_applications', [
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'enquiry_id' => $enquiry->id,
            'status' => 'draft',
            'remarks' => 'First application',
        ]);

        $application = AdmissionApplication::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $this->assertNotNull($application->application_number);
        $this->assertStringStartsWith('APP-', $application->application_number);
        $this->assertNull($application->submitted_at);
    }

    public function test_create_application_without_enquiry_is_allowed(): void
    {
        $college = $this->makeCollege('APLN');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        // Enquiry stays optional: applications may originate without one.
        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_applications', [
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_id' => null,
        ]);
    }

    public function test_submitted_status_sets_submitted_at_server_side_and_browser_timestamp_is_ignored(): void
    {
        $college = $this->makeCollege('APLS');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'submitted',
                'submitted_at' => '2001-01-01 00:00:00',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors();

        $application = AdmissionApplication::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $this->assertNotNull($application->submitted_at);
        $this->assertNotSame('2001', $application->submitted_at->format('Y'));
        $this->assertTrue($application->submitted_at->greaterThan(now()->subMinute()));

        // A draft never carries a submission timestamp, even when forged.
        $applicant2 = $this->makeApplicant($college, ['first_name' => 'Draft']);
        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant2->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'draft',
                'submitted_at' => '2001-01-01 00:00:00',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors();

        $draft = AdmissionApplication::withoutGlobalScopes()->where('applicant_id', $applicant2->id)->first();
        $this->assertNull($draft->submitted_at);
    }

    public function test_validation_requires_applicant_year_program_and_valid_status(): void
    {
        $college = $this->makeCollege('APLV');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors(['applicant_id', 'academic_year_id', 'program_id']);

        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'invalid_status',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors('status');

        // Unknown FK ids are rejected.
        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => 999999,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'enquiry_id' => 999999,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasErrors(['applicant_id', 'enquiry_id']);

        $this->assertSame(0, AdmissionApplication::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_admin_can_update_application_but_applicant_and_number_are_immutable(): void
    {
        $college = $this->makeCollege('APLU');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.update']);
        $applicant = $this->makeApplicant($college);
        $otherApplicant = $this->makeApplicant($college, ['first_name' => 'Other']);
        $year = $this->makeYear($college);
        $year2 = $this->makeYear($college, '2027');
        $program = $this->makeProgram($college);
        $program2 = $this->makeProgram($college, 'MSC');
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'application_number' => 'APP-2026-0001',
            'status' => 'draft',
        ]);

        $this->asCollege($college, $admin)->get(route('admission-applications.edit', $application))->assertOk()->assertSee('APP-2026-0001');

        $this->asCollege($college, $admin)
            ->put(route('admission-applications.update', $application), [
                'applicant_id' => $otherApplicant->id, // must be ignored: immutable
                'application_number' => 'HACKED-1', // must be ignored: server-generated
                'academic_year_id' => $year2->id,
                'program_id' => $program2->id,
                'status' => 'under_review',
                'remarks' => 'Moved to review',
            ], ['Referer' => route('admission-applications.edit', $application)])
            ->assertSessionHas('success');

        $application->refresh();
        $this->assertSame($applicant->id, $application->applicant_id);
        $this->assertSame('APP-2026-0001', $application->application_number);
        $this->assertSame($year2->id, $application->academic_year_id);
        $this->assertSame($program2->id, $application->program_id);
        $this->assertSame('under_review', $application->status);
        $this->assertSame('Moved to review', $application->remarks);
        // First transition out of draft stamps submission server-side.
        $this->assertNotNull($application->submitted_at);
    }

    public function test_status_change_back_to_draft_preserves_submitted_at_history(): void
    {
        $college = $this->makeCollege('APLH');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.update']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'application_number' => 'APP-2026-0009',
            'status' => 'submitted',
            'submitted_at' => '2026-06-15 10:00:00',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('admission-applications.update', $application), [
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.edit', $application)])
            ->assertSessionHas('success');

        $application->refresh();
        $this->assertSame('draft', $application->status);
        $this->assertSame('2026-06-15 10:00:00', $application->submitted_at->format('Y-m-d H:i:s'));
    }

    public function test_soft_delete_hides_application_but_keeps_row(): void
    {
        $college = $this->makeCollege('APLD');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.delete']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'application_number' => 'APP-DEL',
            'status' => 'draft',
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('admission-applications.destroy', $application), [], ['Referer' => route('admission-applications.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('admission_applications', ['id' => $application->id]);
        $this->assertDatabaseHas('admission_applications', ['id' => $application->id, 'application_number' => 'APP-DEL']);
        $this->asCollege($college, $admin)->get(route('admission-applications.index'))->assertDontSee('APP-DEL');
    }

    public function test_index_supports_search_status_filter_and_pagination(): void
    {
        $college = $this->makeCollege('APLI');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $applicant = $this->makeApplicant($college, ['first_name' => 'Search']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        foreach (range(1, 16) as $i) {
            AdmissionApplication::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'application_number' => sprintf('APP-%03d', $i),
                'status' => $i === 16 ? 'approved' : 'draft',
            ]);
        }

        $this->asCollege($college, $admin)->get(route('admission-applications.index'))
            ->assertSee('APP-001')->assertSee('APP-015')->assertDontSee('APP-016')
            ->assertSee('Showing 1–15 of 16 applications.');

        $this->asCollege($college, $admin)->get(route('admission-applications.index', ['page' => 2]))
            ->assertSee('APP-016')->assertDontSee('APP-001');

        $this->asCollege($college, $admin)->get(route('admission-applications.index', ['search' => 'APP-007']))
            ->assertSee('APP-007')->assertDontSee('APP-001');

        $this->asCollege($college, $admin)->get(route('admission-applications.index', ['search' => 'Search']))
            ->assertSee('APP-001');

        $this->asCollege($college, $admin)->get(route('admission-applications.index', ['status' => 'approved']))
            ->assertSee('APP-016')->assertDontSee('APP-001');
    }

    public function test_application_number_is_sequential_per_college_year_and_generated_server_side(): void
    {
        $college = $this->makeCollege('APLQ');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        foreach (['First', 'Second'] as $name) {
            $applicant = $this->makeApplicant($college, ['first_name' => $name]);
            $this->asCollege($college, $admin)
                ->post(route('admission-applications.store'), [
                    'applicant_id' => $applicant->id,
                    'academic_year_id' => $year->id,
                    'program_id' => $program->id,
                    'status' => 'draft',
                ], ['Referer' => route('admission-applications.index')])
                ->assertSessionHasNoErrors();
        }

        $numbers = AdmissionApplication::withoutGlobalScopes()->where('college_id', $college->id)->orderBy('id')->pluck('application_number');
        $this->assertCount(2, $numbers);
        $this->assertNotEquals($numbers[0], $numbers[1]);
        foreach ($numbers as $num) {
            $this->assertStringStartsWith('APP-', $num);
        }
        // Sequential within the college/year context.
        $this->assertSame('APP-2026-0001', $numbers[0]);
        $this->assertSame('APP-2026-0002', $numbers[1]);

        // Browser cannot set the application number.
        $applicant = $this->makeApplicant($college, ['first_name' => 'Third']);
        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'application_number' => 'HACKED-123',
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('admission_applications', ['application_number' => 'HACKED-123']);
    }

    public function test_application_exposes_applicant_year_program_and_optional_enquiry_relations(): void
    {
        $college = $this->makeCollege('APLR');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enquiry = AdmissionEnquiry::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-REL',
            'status' => 'converted',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'enquiry_id' => $enquiry->id,
                'status' => 'submitted',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors();

        $application = AdmissionApplication::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $this->assertSame($applicant->id, $application->applicant->id);
        $this->assertSame($year->id, $application->academicYear->id);
        $this->assertSame($program->id, $application->program->id);
        $this->assertSame($enquiry->id, $application->enquiry->id);

        $applicant2 = $this->makeApplicant($college, ['first_name' => 'NoEnquiry']);
        $this->asCollege($college, $admin)
            ->post(route('admission-applications.store'), [
                'applicant_id' => $applicant2->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'draft',
            ], ['Referer' => route('admission-applications.index')])
            ->assertSessionHasNoErrors();

        $withoutEnquiry = AdmissionApplication::withoutGlobalScopes()->where('applicant_id', $applicant2->id)->first();
        $this->assertNull($withoutEnquiry->enquiry);
    }

    public function test_audit_logs_record_application_create_update_and_delete(): void
    {
        $college = $this->makeCollege('APLA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view', 'admission_applications.create', 'admission_applications.update', 'admission_applications.delete']);
        $applicant = $this->makeApplicant($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)->post(route('admission-applications.store'), [
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'draft',
        ], ['Referer' => route('admission-applications.index')])->assertSessionHasNoErrors();

        $application = AdmissionApplication::withoutGlobalScopes()->firstWhere('college_id', $college->id);

        $this->asCollege($college, $admin)->put(route('admission-applications.update', $application), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'submitted',
            'remarks' => 'Submitted by office',
        ], ['Referer' => route('admission-applications.edit', $application)]);

        $this->asCollege($college, $admin)->delete(route('admission-applications.destroy', $application), [], ['Referer' => route('admission-applications.index')]);

        $base = ['college_id' => $college->id, 'user_id' => $admin->id, 'subject_type' => AdmissionApplication::class, 'subject_id' => $application->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_application.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_application.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_application.deleted']);
    }
}
