<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionMeritList;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionMeritAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_guest_cannot_access_merit(): void
    {
        $this->get(route('admission-merit-lists.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_cannot_view(): void
    {
        $college = $this->makeCollege('MAPA');
        $user = $this->makeUserWithPermissions($college, []);
        $this->asCollege($college, $user)->get(route('admission-merit-lists.index'))->assertForbidden();
    }

    public function test_user_without_publish_permission_cannot_publish(): void
    {
        $college = $this->makeCollege('MAPP');
        $list = AdmissionMeritList::withoutGlobalScopes()->create(['college_id'=>$college->id,'code'=>'P','name'=>'P','status'=>'draft','is_published'=>false]);
        $user = $this->makeUserWithPermissions($college, ['admission_merit.view']);
        $this->asCollege($college, $user)->post(route('admission-merit-lists.publish', $list))->assertForbidden();
    }
}
