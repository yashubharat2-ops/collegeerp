<?php

namespace Tests\Feature\Dashboard;

use App\Models\AcademicAttendance;
use App\Models\Faculty;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

class DashboardAcademicAnalyticsTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    public function test_attendance_summary_matches_the_academic_reports_present_and_late_definition(): void
    {
        $college = $this->makeCollege('DASHATT');
        $context = $this->makeExamContext($college, 'DASHATT');
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $context, 'DASHATT');
        $subjectEnrollment = $this->makeSubjectEnrollment($college, $context, $enrollment);
        $faculty = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'DASH-ATT-FAC',
            'first_name' => 'Attendance',
            'last_name' => 'Faculty',
            'status' => 'active',
        ]);
        foreach ([
            ['2026-08-10', 'present'],
            ['2026-08-11', 'late'],
            ['2026-08-12', 'absent'],
        ] as [$date, $status]) {
            AcademicAttendance::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'student_id' => $student->id,
                'student_enrollment_id' => $enrollment->id,
                'student_subject_enrollment_id' => $subjectEnrollment->id,
                'academic_year_id' => $context['year']->id,
                'academic_term_id' => $context['term']->id,
                'section_id' => $context['sec']->id,
                'subject_id' => $context['sub']->id,
                'faculty_id' => $faculty->id,
                'attendance_date' => $date,
                'status' => $status,
            ]);
        }
        $viewer = $this->makeUserWithPermissions($college, ['academic_attendance.view']);
        $withoutAttendancePermission = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Student attendance')
            ->assertSee('2 of 3 recorded sessions')
            ->assertSee('Formula matches Academic Reports: (present + late) ÷ all recorded attendance rows.')
            ->assertSee('66.7');

        $this->asCollege($college, $withoutAttendancePermission)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Student attendance')
            ->assertDontSee('recorded attendance rows');
    }
}
