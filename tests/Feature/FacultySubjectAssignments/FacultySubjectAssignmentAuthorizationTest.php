<?php

namespace Tests\Feature\FacultySubjectAssignments;

use App\Models\{AcademicYear, Faculty, FacultySubjectAssignment, Subject, User};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultySubjectAssignmentAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('FSAFB');
        $outsider = $this->makeUserWithPermissions($college, []);
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Math', 'code' => 'MTH', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'EMP-01', 'first_name' => 'John', 'last_name' => 'Doe', 'status' => 'active']);
        $assignment = FacultySubjectAssignment::create(['college_id' => $college->id, 'faculty_id' => $faculty->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id, 'status' => 'active']);

        $this->asCollege($college, $outsider)->get(route('faculty-subject-assignments.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('faculty-subject-assignments.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('faculty-subject-assignments.store'), ['faculty_id' => $faculty->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id, 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('faculty-subject-assignments.edit', $assignment))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('faculty-subject-assignments.update', $assignment), ['faculty_id' => $faculty->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id, 'status' => 'inactive'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('faculty-subject-assignments.destroy', $assignment))->assertForbidden();
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('FSAEX');
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subject1 = Subject::create(['college_id' => $college->id, 'name' => 'Math 1', 'code' => 'MTH1', 'status' => 'active']);
        $subject2 = Subject::create(['college_id' => $college->id, 'name' => 'Math 2', 'code' => 'MTH2', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'EMP-01', 'first_name' => 'John', 'last_name' => 'Doe', 'status' => 'active']);
        $assignment = FacultySubjectAssignment::create(['college_id' => $college->id, 'faculty_id' => $faculty->id, 'subject_id' => $subject1->id, 'academic_year_id' => $year->id, 'status' => 'active']);

        $creator = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.create']);
        $this->asCollege($college, $creator)->get(route('faculty-subject-assignments.index'))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('faculty-subject-assignments.store'), [
            'faculty_id' => $faculty->id,
            'subject_id' => $subject2->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
        ], ['Referer' => route('faculty-subject-assignments.index')])->assertSessionHas('success');

        $viewer = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.view']);
        $this->asCollege($college, $viewer)->get(route('faculty-subject-assignments.index'))->assertOk()->assertSee('John Doe');
        $this->asCollege($college, $viewer)->get(route('faculty-subject-assignments.create'))->assertForbidden();

        $editor = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.update']);
        $this->asCollege($college, $editor)->get(route('faculty-subject-assignments.edit', $assignment))->assertOk();
        $this->asCollege($college, $editor)->put(route('faculty-subject-assignments.update', $assignment), [
            'faculty_id' => $faculty->id,
            'subject_id' => $subject1->id,
            'academic_year_id' => $year->id,
            'status' => 'inactive',
        ], ['Referer' => route('faculty-subject-assignments.edit', $assignment)])->assertSessionHas('success');

        $deleter = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.delete']);
        $this->asCollege($college, $deleter)->delete(route('faculty-subject-assignments.destroy', $assignment), [], ['Referer' => route('faculty-subject-assignments.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('faculty_subject_assignments', ['id' => $assignment->id]);
    }
}
