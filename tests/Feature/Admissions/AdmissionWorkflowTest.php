<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admission\Services\AdmissionApplicationWorkflow;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionWorkflowTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeApplicant($college): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$college->id,'first_name'=>'WF','status'=>'active']);
    }

    public function test_workflow_service_defines_valid_transitions(): void
    {
        $this->assertTrue(AdmissionApplicationWorkflow::canTransition('draft','submitted'));
        $this->assertTrue(AdmissionApplicationWorkflow::canTransition('submitted','under_review'));
        $this->assertTrue(AdmissionApplicationWorkflow::canTransition('under_review','approved'));
        $this->assertTrue(AdmissionApplicationWorkflow::canTransition('approved','admitted'));
        $this->assertFalse(AdmissionApplicationWorkflow::canTransition('draft','approved'));
        $this->assertFalse(AdmissionApplicationWorkflow::canTransition('rejected','approved'));
        $this->assertTrue(AdmissionApplicationWorkflow::canTransition('admitted','cancelled'));
        $this->assertFalse(AdmissionApplicationWorkflow::canTransition('admitted','draft'));
    }

    public function test_invalid_transition_is_blocked_in_controller(): void
    {
        $college = $this->makeCollege('WFCT');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update']);
        $applicant = $this->makeApplicant($college);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id'=>$college->id,
            'applicant_id'=>$applicant->id,
            'application_number'=>'APP-WF',
            'status'=>'draft',
        ]);

        // draft -> approved is invalid
        $this->asCollege($college, $admin)
            ->put(route('admission-applications.update', $application), [
                'academic_year_id' => null,
                'program_id' => null,
                'status' => 'approved',
            ], ['Referer'=>route('admission-applications.edit', $application)])
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame('draft', $application->status);

        // draft -> submitted is valid
        $year = \App\Models\AcademicYear::withoutGlobalScopes()->create([
            'college_id'=>$college->id,
            'name'=>'2026-27',
            'code'=>'2026',
            'starts_on'=>'2026-06-01',
            'ends_on'=>'2027-05-31',
            'status'=>'active',
        ]);
        $program = \App\Models\Program::withoutGlobalScopes()->create([
            'college_id'=>$college->id,
            'name'=>'BSc',
            'code'=>'BSC',
            'status'=>'active',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('admission-applications.update', $application), [
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'submitted',
            ], ['Referer'=>route('admission-applications.edit', $application)])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame('submitted', $application->status);
    }

    public function test_admitted_status_flow(): void
    {
        $college = $this->makeCollege('WFAD');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view','admission_applications.update']);
        $applicant = $this->makeApplicant($college);
        $year = \App\Models\AcademicYear::withoutGlobalScopes()->create([
            'college_id'=>$college->id,
            'name'=>'2026-27',
            'code'=>'2026',
            'starts_on'=>'2026-06-01',
            'ends_on'=>'2027-05-31',
            'status'=>'active',
        ]);
        $program = \App\Models\Program::withoutGlobalScopes()->create([
            'college_id'=>$college->id,
            'name'=>'BSc',
            'code'=>'BSC',
            'status'=>'active',
        ]);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id'=>$college->id,
            'applicant_id'=>$applicant->id,
            'academic_year_id'=>$year->id,
            'program_id'=>$program->id,
            'application_number'=>'APP-AD',
            'status'=>'approved',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('admission-applications.update', $application), [
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'admitted',
            ], ['Referer'=>route('admission-applications.edit', $application)])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame('admitted', $application->status);
    }
}
