<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionApplicantAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_guest_cannot_access_applicants(): void
    {
        $this->get(route('admission-applicants.index'))->assertRedirect(route('login'));
        $this->get(route('admission-applicants.create'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $college = $this->makeCollege('AUTH');
        $user = $this->makeUserWithPermissions($college, []); // no perms

        $this->asCollege($college, $user)->get(route('admission-applicants.index'))->assertForbidden();
        $this->asCollege($college, $user)->get(route('admission-applicants.create'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('admission-applicants.store'), [
            'first_name' => 'Test',
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_user_with_view_can_list_but_not_create(): void
    {
        $college = $this->makeCollege('VIEW');
        $viewer = $this->makeUserWithPermissions($college, ['admission_applicants.view']);

        $this->asCollege($college, $viewer)->get(route('admission-applicants.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('admission-applicants.create'))->assertForbidden();
    }

    public function test_permissions_are_resolved_per_college(): void
    {
        $collegeA = $this->makeCollege('PRVA');
        $collegeB = $this->makeCollege('PRVB');
        $user = $this->makeUserWithPermissions($collegeB, ['admission_applicants.view']);
        $user->colleges()->attach($collegeA->id);

        $this->asCollege($collegeB, $user)->get(route('admission-applicants.index'))->assertOk();
        $this->asCollege($collegeA, $user)->get(route('admission-applicants.index'))->assertForbidden();
    }
}
