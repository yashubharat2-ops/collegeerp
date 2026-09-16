<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use App\Models\AuditLog;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionApplicantManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_applicant_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('APPC');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-applicants.store'), [
                'first_name' => 'Rahul',
                'middle_name' => 'Kumar',
                'last_name' => 'Sharma',
                'email' => 'rahul@example.test',
                'phone' => '9999999999',
                'alternate_phone' => '8888888888',
                'gender' => 'male',
                'date_of_birth' => '2000-01-01',
                'address' => '123 Test Street',
                'status' => 'active',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertRedirect(route('admission-applicants.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_applicants', [
            'college_id' => $college->id,
            'first_name' => 'Rahul',
            'last_name' => 'Sharma',
            'email' => 'rahul@example.test',
            'phone' => '9999999999',
            'status' => 'active',
        ]);
    }

    public function test_minimal_applicant_for_walk_in_enquiry_is_allowed(): void
    {
        $college = $this->makeCollege('MINI');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.create']);

        // Only first_name required, last_name optional, phone/email nullable
        $this->asCollege($college, $admin)
            ->post(route('admission-applicants.store'), [
                'first_name' => 'WalkIn',
                'status' => 'active',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_applicants', [
            'college_id' => $college->id,
            'first_name' => 'WalkIn',
        ]);
    }

    public function test_validation_rejects_missing_first_name_bad_email_and_dates(): void
    {
        $college = $this->makeCollege('VALA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-applicants.store'), [
                'first_name' => '',
                'email' => 'not-an-email',
                'gender' => 'unknown',
                'date_of_birth' => 'not-a-date',
                'status' => 'archived',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHasErrors(['first_name', 'email', 'gender', 'date_of_birth', 'status']);

        $this->asCollege($college, $admin)
            ->post(route('admission-applicants.store'), [
                'first_name' => str_repeat('a', 256),
                'middle_name' => str_repeat('b', 256),
                'last_name' => str_repeat('c', 256),
                'email' => str_repeat('d', 250).'@example.test',
                'phone' => str_repeat('1', 31),
                'address' => str_repeat('x', 2001),
                'status' => 'active',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHasErrors(['first_name', 'middle_name', 'last_name', 'email', 'phone', 'address']);

        // Future date and too old date
        $this->asCollege($college, $admin)
            ->post(route('admission-applicants.store'), [
                'first_name' => 'Future',
                'date_of_birth' => now()->addDay()->format('Y-m-d'),
                'status' => 'active',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHasErrors('date_of_birth');

        $this->asCollege($college, $admin)
            ->post(route('admission-applicants.store'), [
                'first_name' => 'Old',
                'date_of_birth' => '1899-12-31',
                'status' => 'active',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHasErrors('date_of_birth');

        $this->assertSame(0, AdmissionApplicant::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_admin_can_update_applicant(): void
    {
        $college = $this->makeCollege('UPDA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.update']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Asha',
            'last_name' => 'Patel',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)->get(route('admission-applicants.edit', $applicant))->assertOk()->assertSee('Asha');

        $this->asCollege($college, $admin)
            ->put(route('admission-applicants.update', $applicant), [
                'first_name' => 'Asha Updated',
                'last_name' => 'Patel',
                'email' => 'asha@example.test',
                'status' => 'inactive',
            ], ['Referer' => route('admission-applicants.edit', $applicant)])
            ->assertSessionHas('success');

        $applicant->refresh();
        $this->assertSame('Asha Updated', $applicant->first_name);
        $this->assertSame('inactive', $applicant->status);
        $this->assertSame('asha@example.test', $applicant->email);
    }

    public function test_soft_delete_hides_applicant_but_keeps_row(): void
    {
        $college = $this->makeCollege('DELA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.delete']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'DeleteMe',
            'last_name' => 'Test',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('admission-applicants.destroy', $applicant), [], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('admission_applicants', ['id' => $applicant->id]);
        $this->assertDatabaseHas('admission_applicants', ['id' => $applicant->id, 'first_name' => 'DeleteMe']);
        $this->asCollege($college, $admin)->get(route('admission-applicants.index'))->assertDontSee('DeleteMe');
    }

    public function test_index_supports_search_status_filter_and_pagination(): void
    {
        $college = $this->makeCollege('IDXA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view']);
        foreach (range(1, 16) as $i) {
            AdmissionApplicant::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'first_name' => sprintf('Applicant %02d', $i),
                'last_name' => 'Test',
                'email' => sprintf('app%02d@example.test', $i),
                'phone' => sprintf('90000000%02d', $i),
                'status' => $i === 16 ? 'inactive' : 'active',
            ]);
        }

        $this->asCollege($college, $admin)->get(route('admission-applicants.index'))
            ->assertSee('Applicant 01')->assertSee('Applicant 15')->assertDontSee('Applicant 16')
            ->assertSee('Showing 1–15 of 16 applicants.');

        $this->asCollege($college, $admin)->get(route('admission-applicants.index', ['page' => 2]))
            ->assertSee('Applicant 16')->assertDontSee('Applicant 01');

        $this->asCollege($college, $admin)->get(route('admission-applicants.index', ['search' => 'Applicant 07']))
            ->assertSee('Applicant 07')->assertDontSee('Applicant 01');

        $this->asCollege($college, $admin)->get(route('admission-applicants.index', ['search' => 'app07@example']))
            ->assertSee('Applicant 07');

        $this->asCollege($college, $admin)->get(route('admission-applicants.index', ['search' => '9000000007']))
            ->assertSee('Applicant 07');

        $this->asCollege($college, $admin)->get(route('admission-applicants.index', ['status' => 'inactive']))
            ->assertSee('Applicant 16')->assertDontSee('Applicant 01');
    }

    public function test_audit_logs_record_create_update_and_delete(): void
    {
        $college = $this->makeCollege('AUDA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.create', 'admission_applicants.update', 'admission_applicants.delete']);

        $this->asCollege($college, $admin)->post(route('admission-applicants.store'), [
            'first_name' => 'Audit',
            'last_name' => 'Test',
            'status' => 'active',
        ], ['Referer' => route('admission-applicants.index')])->assertSessionHasNoErrors();

        $applicant = AdmissionApplicant::withoutGlobalScopes()->firstWhere('first_name', 'Audit');

        $this->asCollege($college, $admin)->put(route('admission-applicants.update', $applicant), [
            'first_name' => 'Audit Updated',
            'last_name' => 'Test',
            'status' => 'active',
        ], ['Referer' => route('admission-applicants.edit', $applicant)]);

        $this->asCollege($college, $admin)->delete(route('admission-applicants.destroy', $applicant), [], ['Referer' => route('admission-applicants.index')]);

        $base = ['college_id' => $college->id, 'user_id' => $admin->id, 'subject_type' => AdmissionApplicant::class, 'subject_id' => $applicant->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_applicant.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_applicant.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'admission_applicant.deleted']);

        $updated = AuditLog::where($base + ['action' => 'admission_applicant.updated'])->firstOrFail();
        $this->assertSame('Audit', $updated->old_values['first_name']);
        $this->assertSame('Audit Updated', $updated->new_values['first_name']);
    }
}
