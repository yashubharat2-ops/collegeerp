<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_admissions_of_active_college(): void
    {
        $collegeA = $this->makeCollege('ATNA');
        $collegeB = $this->makeCollege('ATNB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admissions.view']);

        $appA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'first_name'=>'A','status'=>'active']);
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'first_name'=>'B','status'=>'active']);

        $applA = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'applicant_id'=>$appA->id,'application_number'=>'APP-A','status'=>'approved']);
        $applB = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$appB->id,'application_number'=>'APP-B','status'=>'approved']);

        Admission::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'applicant_id'=>$appA->id,'application_id'=>$applA->id,'admission_number'=>'ADM-A','status'=>'active']);
        Admission::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$appB->id,'application_id'=>$applB->id,'admission_number'=>'ADM-B','status'=>'active']);

        $this->asCollege($collegeA, $adminA)->get(route('admissions.index'))->assertSee('ADM-A')->assertDontSee('ADM-B');
    }

    public function test_cross_college_admission_cannot_be_accessed(): void
    {
        $collegeA = $this->makeCollege('ATNC');
        $collegeB = $this->makeCollege('ATND');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'first_name'=>'B','status'=>'active']);
        $applB = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$appB->id,'application_number'=>'APP-B','status'=>'approved']);
        $admB = Admission::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$appB->id,'application_id'=>$applB->id,'admission_number'=>'ADM-B','status'=>'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['admissions.view','admissions.update','admissions.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('admissions.edit', $admB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('admissions.update', $admB), ['status'=>'cancelled'], ['Referer'=>route('admissions.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('admissions.destroy', $admB))->assertNotFound();
    }

    public function test_cross_college_application_cannot_be_admitted(): void
    {
        $collegeA = $this->makeCollege('ATNE');
        $collegeB = $this->makeCollege('ATNF');
        $appB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'first_name'=>'B','status'=>'active']);
        $applB = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$appB->id,'application_number'=>'APP-B','status'=>'approved']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admissions.view','admissions.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admissions.store'), ['application_id'=>$applB->id], ['Referer'=>route('admissions.index')])
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, Admission::withoutGlobalScopes()->where('college_id',$collegeA->id)->count());
    }
}
