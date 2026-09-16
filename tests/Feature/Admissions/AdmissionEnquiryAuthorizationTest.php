<?php

namespace Tests\Feature\Admissions;

use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionEnquiryAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_guest_cannot_access_enquiries(): void
    {
        $this->get(route('admission-enquiries.index'))->assertRedirect(route('login'));
        $this->get(route('admission-enquiries.create'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $college = $this->makeCollege('AUTHE');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)->get(route('admission-enquiries.index'))->assertForbidden();
        $this->asCollege($college, $user)->get(route('admission-enquiries.create'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('admission-enquiries.store'), [
            'applicant_first_name' => 'Test',
            'status' => 'new',
        ])->assertForbidden();
    }
}
