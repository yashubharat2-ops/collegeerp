<?php

namespace Tests\Feature\Admissions;

use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_guest_cannot_access_admissions(): void
    {
        $this->get(route('admissions.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_cannot_view(): void
    {
        $college = $this->makeCollege('AAUA');
        $user = $this->makeUserWithPermissions($college, []);
        $this->asCollege($college, $user)->get(route('admissions.index'))->assertForbidden();
    }

    public function test_user_without_create_cannot_create(): void
    {
        $college = $this->makeCollege('AAUB');
        $user = $this->makeUserWithPermissions($college, ['admissions.view']);
        $this->asCollege($college, $user)->get(route('admissions.create'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('admissions.store'), ['application_id'=>1])->assertForbidden();
    }

    public function test_user_without_update_cannot_update(): void
    {
        $college = $this->makeCollege('AAUC');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$college->id,'first_name'=>'A','status'=>'active']);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'application_number'=>'APP','status'=>'approved']);
        $adm = Admission::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'application_id'=>$app->id,'admission_number'=>'ADM','status'=>'active']);
        $user = $this->makeUserWithPermissions($college, ['admissions.view']);
        $this->asCollege($college, $user)->get(route('admissions.edit', $adm))->assertForbidden();
        $this->asCollege($college, $user)->put(route('admissions.update', $adm), ['status'=>'cancelled'])->assertForbidden();
    }
}
