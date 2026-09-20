<?php

namespace Tests\Feature\Academics;

use App\Models\{AcademicTerm, AcademicTimetable, AcademicYear, Campus, Faculty, Program, Section, Subject};
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

    public function test_subject_enrollment_create_form_uses_platform_year_attributes(): void
    {
        $college = $this->makeCollege('ACFORM');
        $user = $this->makeUserWithPermissions($college, ['academic_subject_enrollments.create']);

        $this->asCollege($college, $user)
            ->get(route('academic-subject-enrollments.create'))
            ->assertOk();
    }

    public function test_workload_index_loads_with_sqlite_compatible_duration_calculation(): void
    {
        $college = $this->makeCollege('ACWORK');
        $user = $this->makeUserWithPermissions($college, ['academic_workload.view']);
        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-27', 'code' => 'AY26', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => 'Term 1', 'code' => 'T1', 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $campus = Campus::create(['college_id' => $college->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'B.Com', 'code' => 'BCOM', 'status' => 'active']);
        $section = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id, 'campus_id' => $campus->id, 'name' => 'A', 'code' => 'A', 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'code' => 'ACC101', 'name' => 'Accounting', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'F001', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'active']);
        AcademicTimetable::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'academic_term_id' => $term->id, 'program_id' => $program->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'faculty_id' => $faculty->id, 'campus_id' => $campus->id, 'day_of_week' => 1, 'period' => 1, 'start_time' => '09:00', 'end_time' => '10:30', 'status' => 'active']);

        $this->asCollege($college, $user)
            ->get(route('academic-workload.index'))
            ->assertOk()
            ->assertSee('1.50');
    }
}
