<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionManagementTest extends TestCase
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

    private function makeApplicant($college): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Final',
            'last_name' => 'Admit',
            'status' => 'active',
        ]);
    }

    private function makeApplication($college, $applicant, $year = null, $program = null, string $status = 'approved'): AdmissionApplication
    {
        return AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year?->id,
            'program_id' => $program?->id,
            'application_number' => 'APP-'.uniqid(),
            'status' => $status,
        ]);
    }

    public function test_admin_can_create_admission_from_approved_application(): void
    {
        $college = $this->makeCollege('ADMC');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.create','admission_applications.view']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant, $year, $program, 'approved');

        $this->asCollege($college, $admin)
            ->post(route('admissions.store'), [
                'application_id' => $application->id,
                'admission_date' => '2026-07-01',
                'status' => 'active',
                'remarks' => 'Admitted',
            ], ['Referer'=>route('admissions.index')])
            ->assertRedirect(route('admissions.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admissions', [
            'college_id' => $college->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'active',
        ]);

        $admission = Admission::withoutGlobalScopes()->firstWhere('college_id', $college->id);
        $this->assertNotNull($admission->admission_number);
        $this->assertStringStartsWith('ADM-', $admission->admission_number);

        // Application should transition to admitted
        $application->refresh();
        $this->assertSame('admitted', $application->status);
    }

    public function test_duplicate_prevention_per_application(): void
    {
        $college = $this->makeCollege('ADMD');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.create']);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant, null, null, 'approved');

        $this->asCollege($college, $admin)->post(route('admissions.store'), [
            'application_id' => $application->id,
            'status' => 'active',
        ], ['Referer'=>route('admissions.index')])->assertSessionHas('success');

        $this->asCollege($college, $admin)->post(route('admissions.store'), [
            'application_id' => $application->id,
            'status' => 'active',
        ], ['Referer'=>route('admissions.index')])->assertSessionHasErrors('application_id');

        $this->assertSame(1, Admission::withoutGlobalScopes()->where('college_id',$college->id)->count());
    }

    public function test_cannot_admit_draft_rejected_cancelled_application(): void
    {
        $college = $this->makeCollege('ADMR');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.create']);
        $applicant = $this->makeApplicant($college);

        foreach (['draft','rejected','cancelled'] as $badStatus) {
            $app = $this->makeApplication($college, $applicant, null, null, $badStatus);
            $this->asCollege($college, $admin)->post(route('admissions.store'), [
                'application_id' => $app->id,
            ], ['Referer'=>route('admissions.index')])->assertSessionHasErrors('application_id');
        }

        $this->assertSame(0, Admission::withoutGlobalScopes()->where('college_id',$college->id)->count());
    }

    public function test_admission_number_generated_server_side_and_browser_value_ignored(): void
    {
        $college = $this->makeCollege('ADMN');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.create']);
        $applicant = $this->makeApplicant($college);
        $app = $this->makeApplication($college, $applicant, null, null, 'approved');

        $this->asCollege($college, $admin)->post(route('admissions.store'), [
            'application_id' => $app->id,
            'admission_number' => 'HACKED-123',
            'college_id' => 999,
            'applicant_id' => 999,
        ], ['Referer'=>route('admissions.index')])->assertSessionHas('success');

        $admission = Admission::withoutGlobalScopes()->firstWhere('college_id',$college->id);
        $this->assertNotSame('HACKED-123', $admission->admission_number);
        $this->assertSame($college->id, $admission->college_id);
        $this->assertSame($applicant->id, $admission->applicant_id);
    }

    public function test_admin_can_update_and_cancel_admission(): void
    {
        $college = $this->makeCollege('ADMU');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.update']);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant, null, null, 'approved');
        $admission = Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-2026-0001',
            'status' => 'active',
            'admission_date' => '2026-07-01',
        ]);

        $this->asCollege($college, $admin)->get(route('admissions.edit', $admission))->assertOk()->assertSee('ADM-2026-0001');

        $this->asCollege($college, $admin)
            ->put(route('admissions.update', $admission), [
                'status' => 'completed',
                'remarks' => 'Completed',
            ], ['Referer'=>route('admissions.edit', $admission)])
            ->assertSessionHas('success');

        $admission->refresh();
        $this->assertSame('completed', $admission->status);

        $this->asCollege($college, $admin)
            ->post(route('admissions.cancel', $admission), [
                'remarks' => 'Cancelled by admin',
            ], ['Referer'=>route('admissions.index')])
            ->assertSessionHas('success');

        $admission->refresh();
        $this->assertSame('cancelled', $admission->status);
        $this->assertSame('Cancelled by admin', $admission->remarks);
    }

    public function test_soft_delete_hides_admission(): void
    {
        $college = $this->makeCollege('ADMS');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.delete']);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant);
        $admission = Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-DEL',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)->delete(route('admissions.destroy', $admission), [], ['Referer'=>route('admissions.index')])->assertSessionHas('success');

        $this->assertSoftDeleted('admissions', ['id'=>$admission->id]);
        $this->asCollege($college, $admin)->get(route('admissions.index'))->assertDontSee('ADM-DEL');
    }

    public function test_index_filtering_and_deterministic_ordering(): void
    {
        $college = $this->makeCollege('ADMI');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view']);
        $applicant = $this->makeApplicant($college);
        foreach (range(1,16) as $i) {
            $app = $this->makeApplication($college, $applicant);
            Admission::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'application_id' => $app->id,
                'applicant_id' => $applicant->id,
                'admission_number' => sprintf('ADM-%03d', $i),
                'status' => $i === 16 ? 'cancelled' : 'active',
                'admission_date' => '2026-07-'.sprintf('%02d', ($i % 28) + 1),
            ]);
        }

        $this->asCollege($college, $admin)->get(route('admissions.index'))
            ->assertSee('ADM-001')->assertSee('ADM-015')->assertDontSee('ADM-016');

        $this->asCollege($college, $admin)->get(route('admissions.index', ['status'=>'cancelled']))
            ->assertSee('ADM-016')->assertDontSee('ADM-001');
    }

    public function test_audit_logs_for_admission_lifecycle(): void
    {
        $college = $this->makeCollege('ADMA');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.create','admissions.update','admissions.delete']);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant, null, null, 'approved');

        $this->asCollege($college, $admin)->post(route('admissions.store'), [
            'application_id' => $application->id,
        ], ['Referer'=>route('admissions.index')]);

        $admission = Admission::withoutGlobalScopes()->firstWhere('college_id',$college->id);

        $this->asCollege($college, $admin)->put(route('admissions.update', $admission), [
            'status' => 'completed',
        ], ['Referer'=>route('admissions.edit', $admission)]);

        $this->asCollege($college, $admin)->delete(route('admissions.destroy', $admission), [], ['Referer'=>route('admissions.index')]);

        $base = ['college_id'=>$college->id,'user_id'=>$admin->id,'subject_type'=>Admission::class,'subject_id'=>$admission->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action'=>'admission.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action'=>'admission.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action'=>'admission.deleted']);
    }

    public function test_xss_safe_rendering(): void
    {
        $college = $this->makeCollege('ADMX');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'XSS',
            'status' => 'active',
        ]);
        $app = $this->makeApplication($college, $applicant);
        Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'application_id' => $app->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-XSS',
            'status' => 'active',
            'remarks' => '<img src=x onerror=alert(2)>',
        ]);

        $response = $this->asCollege($college, $admin)->get(route('admissions.index'));
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee(e('<script>alert(1)</script>'), false);
    }

    public function test_admission_number_sequential_per_college(): void
    {
        $college = $this->makeCollege('ADMQ');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view','admissions.create']);
        $year = $this->makeYear($college, '2026');

        foreach (['First','Second'] as $name) {
            $applicant = $this->makeApplicant($college);
            $applicant->update(['first_name'=>$name]);
            $app = $this->makeApplication($college, $applicant, $year, null, 'approved');
            $this->asCollege($college, $admin)->post(route('admissions.store'), [
                'application_id' => $app->id,
            ], ['Referer'=>route('admissions.index')])->assertSessionHasNoErrors();
        }

        $numbers = Admission::withoutGlobalScopes()->where('college_id',$college->id)->orderBy('id')->pluck('admission_number');
        $this->assertCount(2, $numbers);
        $this->assertNotEquals($numbers[0], $numbers[1]);
        $this->assertStringStartsWith('ADM-', $numbers[0]);
    }
}
