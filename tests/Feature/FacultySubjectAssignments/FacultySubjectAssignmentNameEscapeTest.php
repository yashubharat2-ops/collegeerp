<?php

namespace Tests\Feature\FacultySubjectAssignments;

use App\Models\{AcademicYear, Faculty, FacultySubjectAssignment, Subject};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultySubjectAssignmentNameEscapeTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_javascript_breaking_name_is_encoded_in_delete_confirmation(): void
    {
        $college = $this->makeCollege('FSAXS');
        $admin = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.view', 'faculty_subject_assignments.delete']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Algorithms', 'code' => 'CS201', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'XSS-FAC', 'first_name' => 'Malicious', 'last_name' => "O'Neill", 'status' => 'active']);

        FacultySubjectAssignment::create([
            'college_id' => $college->id,
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
        ]);

        $html = $this->asCollege($college, $admin)->get(route('faculty-subject-assignments.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("');alert('xss');('", $html);
        $this->assertStringContainsString('O&#039;Neill', $html);
    }
}
