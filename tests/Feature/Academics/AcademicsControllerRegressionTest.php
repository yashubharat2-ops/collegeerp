<?php

namespace Tests\Feature\Academics;

use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AcademicsControllerRegressionTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_subject_enrollment_index_loads_without_controller_visibility_fatal(): void
    {
        $college = $this->makeCollege('ACCTRL');
        $user = $this->makeUserWithPermissions($college, ['academic_subject_enrollments.view']);

        $this->asCollege($college, $user)
            ->get(route('academic-subject-enrollments.index'))
            ->assertOk();
    }
}
