<?php

namespace Tests\Feature\ExamSchedules;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ExamScheduleAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_unauthorized_user_cannot_view_exam_schedules(): void
    {
        $college = $this->makeCollege('ESAU1');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)
            ->get(route('exam-schedules.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_exam_schedule(): void
    {
        $college = $this->makeCollege('ESAU2');
        $viewer = $this->makeUserWithPermissions($college, ['exam_schedules.view']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026', 'code' => 'Y26', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => 'T1', 'code' => 'T1', 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $exam = Examination::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'academic_term_id' => $term->id, 'name' => 'Exam', 'code' => 'EX', 'exam_type' => 'Internal', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'status' => 'published']);
        $prog = Program::create(['college_id' => $college->id, 'name' => 'CS', 'code' => 'CS', 'status' => 'active']);
        $sec = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $prog->id, 'name' => 'A', 'code' => 'A', 'status' => 'active']);
        $sub = Subject::create(['college_id' => $college->id, 'name' => 'Math', 'code' => 'MTH', 'status' => 'active']);

        $this->asCollege($college, $viewer)
            ->get(route('exam-schedules.create'))
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->post(route('exam-schedules.store'), [
                'examination_id' => $exam->id,
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'program_id' => $prog->id,
                'section_id' => $sec->id,
                'subject_id' => $sub->id,
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ])
            ->assertForbidden();

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_completed_schedule_cannot_be_deleted_by_regular_admin(): void
    {
        $college = $this->makeCollege('ESAU3');
        $admin = $this->makeUserWithPermissions($college, ['exam_schedules.view', 'exam_schedules.update', 'exam_schedules.delete']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026', 'code' => 'Y26', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => 'T1', 'code' => 'T1', 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $exam = Examination::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'academic_term_id' => $term->id, 'name' => 'Exam', 'code' => 'EX', 'exam_type' => 'Internal', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'status' => 'published']);
        $prog = Program::create(['college_id' => $college->id, 'name' => 'CS', 'code' => 'CS', 'status' => 'active']);
        $sec = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $prog->id, 'name' => 'A', 'code' => 'A', 'status' => 'active']);
        $sub = Subject::create(['college_id' => $college->id, 'name' => 'Math', 'code' => 'MTH', 'status' => 'active']);

        $schedule = ExamSchedule::create([
            'college_id' => $college->id,
            'examination_id' => $exam->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $prog->id,
            'section_id' => $sec->id,
            'subject_id' => $sub->id,
            'exam_date' => '2026-10-02',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => ExamSchedule::STATUS_COMPLETED,
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('exam-schedules.destroy', $schedule))
            ->assertForbidden();

        $this->assertNull($schedule->fresh()->deleted_at);

        $superAdmin = $this->makeUserWithPermissions($college, ['exam_schedules.view', 'exam_schedules.delete'], true);
        $this->asCollege($college, $superAdmin)
            ->delete(route('exam-schedules.destroy', $schedule))
            ->assertRedirect(route('exam-schedules.index'));

        $this->assertSoftDeleted('exam_schedules', ['id' => $schedule->id]);
    }
}
