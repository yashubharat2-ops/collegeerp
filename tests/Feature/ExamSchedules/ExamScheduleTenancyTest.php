<?php

namespace Tests\Feature\ExamSchedules;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ExamScheduleTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    private function createCollegeContext(string $prefix): array
    {
        $college = $this->makeCollege($prefix);
        $admin = $this->makeUserWithPermissions($college, ['exam_schedules.view', 'exam_schedules.create', 'exam_schedules.update', 'exam_schedules.delete']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => "2026 {$prefix}", 'code' => "AY-{$prefix}", 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => "Term {$prefix}", 'code' => "T-{$prefix}", 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $prog = Program::create(['college_id' => $college->id, 'name' => "Prog {$prefix}", 'code' => "P-{$prefix}", 'status' => 'active']);
        $sec = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $prog->id, 'name' => "Sec {$prefix}", 'code' => "S-{$prefix}", 'status' => 'active']);
        $sub = Subject::create(['college_id' => $college->id, 'name' => "Sub {$prefix}", 'code' => "SB-{$prefix}", 'status' => 'active']);
        $fac = Faculty::create(['college_id' => $college->id, 'employee_code' => "F-{$prefix}", 'first_name' => 'Prof', 'last_name' => $prefix, 'status' => 'active']);
        $camp = Campus::create(['college_id' => $college->id, 'name' => "Camp {$prefix}", 'code' => "C-{$prefix}", 'status' => 'active']);
        $exam = Examination::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'academic_term_id' => $term->id, 'name' => "Exam {$prefix}", 'code' => "E-{$prefix}", 'exam_type' => 'Internal', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'status' => 'published']);

        return compact('college', 'admin', 'year', 'term', 'prog', 'sec', 'sub', 'fac', 'camp', 'exam');
    }

    /**
     * Requirement 28: tenant isolation works
     */
    public function test_tenant_isolation_list_only_shows_active_college_schedules(): void
    {
        $ctxA = $this->createCollegeContext('SCTA');
        $ctxB = $this->createCollegeContext('SCTB');

        ExamSchedule::create([
            'college_id' => $ctxA['college']->id,
            'examination_id' => $ctxA['exam']->id,
            'academic_year_id' => $ctxA['year']->id,
            'academic_term_id' => $ctxA['term']->id,
            'program_id' => $ctxA['prog']->id,
            'section_id' => $ctxA['sec']->id,
            'subject_id' => $ctxA['sub']->id,
            'exam_date' => '2026-10-02',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'room' => 'Hall Alpha',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        ExamSchedule::create([
            'college_id' => $ctxB['college']->id,
            'examination_id' => $ctxB['exam']->id,
            'academic_year_id' => $ctxB['year']->id,
            'academic_term_id' => $ctxB['term']->id,
            'program_id' => $ctxB['prog']->id,
            'section_id' => $ctxB['sec']->id,
            'subject_id' => $ctxB['sub']->id,
            'exam_date' => '2026-10-02',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'room' => 'Hall Beta',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->get(route('exam-schedules.index'))
            ->assertSee('Hall Alpha')
            ->assertDontSee('Hall Beta');
    }

    /**
     * Requirement 14: cross-tenant examination rejected
     */
    public function test_cross_tenant_examination_rejected(): void
    {
        $ctxA = $this->createCollegeContext('SC14A');
        $ctxB = $this->createCollegeContext('SC14B');

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctxB['exam']->id, // Foreign examination
                'academic_year_id' => $ctxA['year']->id,
                'academic_term_id' => $ctxA['term']->id,
                'program_id' => $ctxA['prog']->id,
                'section_id' => $ctxA['sec']->id,
                'subject_id' => $ctxA['sub']->id,
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['examination_id']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctxA['college']->id)->count());
    }

    /**
     * Requirement 15: cross-tenant section rejected
     */
    public function test_cross_tenant_section_rejected(): void
    {
        $ctxA = $this->createCollegeContext('SC15A');
        $ctxB = $this->createCollegeContext('SC15B');

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctxA['exam']->id,
                'academic_year_id' => $ctxA['year']->id,
                'academic_term_id' => $ctxA['term']->id,
                'program_id' => $ctxA['prog']->id,
                'section_id' => $ctxB['sec']->id, // Foreign section
                'subject_id' => $ctxA['sub']->id,
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['section_id']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctxA['college']->id)->count());
    }

    /**
     * Requirement 16: cross-tenant subject rejected
     */
    public function test_cross_tenant_subject_rejected(): void
    {
        $ctxA = $this->createCollegeContext('SC16A');
        $ctxB = $this->createCollegeContext('SC16B');

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctxA['exam']->id,
                'academic_year_id' => $ctxA['year']->id,
                'academic_term_id' => $ctxA['term']->id,
                'program_id' => $ctxA['prog']->id,
                'section_id' => $ctxA['sec']->id,
                'subject_id' => $ctxB['sub']->id, // Foreign subject
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['subject_id']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctxA['college']->id)->count());
    }

    public function test_cross_tenant_faculty_and_campus_rejected(): void
    {
        $ctxA = $this->createCollegeContext('SCXFA');
        $ctxB = $this->createCollegeContext('SCXFB');

        // Cross-tenant faculty rejected
        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctxA['exam']->id,
                'academic_year_id' => $ctxA['year']->id,
                'academic_term_id' => $ctxA['term']->id,
                'program_id' => $ctxA['prog']->id,
                'section_id' => $ctxA['sec']->id,
                'subject_id' => $ctxA['sub']->id,
                'faculty_id' => $ctxB['fac']->id, // Foreign faculty
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['faculty_id']);

        // Cross-tenant campus rejected
        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctxA['exam']->id,
                'academic_year_id' => $ctxA['year']->id,
                'academic_term_id' => $ctxA['term']->id,
                'program_id' => $ctxA['prog']->id,
                'section_id' => $ctxA['sec']->id,
                'subject_id' => $ctxA['sub']->id,
                'campus_id' => $ctxB['camp']->id, // Foreign campus
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['campus_id']);
    }

    public function test_cannot_edit_or_delete_foreign_college_schedule(): void
    {
        $ctxA = $this->createCollegeContext('SCFGA');
        $ctxB = $this->createCollegeContext('SCFGB');

        $foreignSchedule = ExamSchedule::create([
            'college_id' => $ctxB['college']->id,
            'examination_id' => $ctxB['exam']->id,
            'academic_year_id' => $ctxB['year']->id,
            'academic_term_id' => $ctxB['term']->id,
            'program_id' => $ctxB['prog']->id,
            'section_id' => $ctxB['sec']->id,
            'subject_id' => $ctxB['sub']->id,
            'exam_date' => '2026-10-02',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'room' => 'Foreign Hall',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->get(route('exam-schedules.edit', $foreignSchedule))
            ->assertNotFound();

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->put(route('exam-schedules.update', $foreignSchedule), [
                'examination_id' => $ctxB['exam']->id,
                'academic_year_id' => $ctxB['year']->id,
                'academic_term_id' => $ctxB['term']->id,
                'program_id' => $ctxB['prog']->id,
                'section_id' => $ctxB['sec']->id,
                'subject_id' => $ctxB['sub']->id,
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'cancelled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertNotFound();

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->delete(route('exam-schedules.destroy', $foreignSchedule), [], ['Referer' => route('exam-schedules.index')])
            ->assertNotFound();

        $this->assertSame('scheduled', $foreignSchedule->fresh()->status);
        $this->assertNull($foreignSchedule->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $ctxA = $this->createCollegeContext('SCSPA');
        $ctxB = $this->createCollegeContext('SCSPB');

        $this->asCollege($ctxA['college'], $ctxA['admin'])
            ->post(route('exam-schedules.store'), [
                'college_id' => $ctxB['college']->id, // Spoofing attempt
                'examination_id' => $ctxA['exam']->id,
                'academic_year_id' => $ctxA['year']->id,
                'academic_term_id' => $ctxA['term']->id,
                'program_id' => $ctxA['prog']->id,
                'section_id' => $ctxA['sec']->id,
                'subject_id' => $ctxA['sub']->id,
                'exam_date' => '2026-10-02',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exam_schedules', [
            'college_id' => $ctxA['college']->id,
            'examination_id' => $ctxA['exam']->id,
            'subject_id' => $ctxA['sub']->id,
        ]);
        $this->assertDatabaseMissing('exam_schedules', [
            'college_id' => $ctxB['college']->id,
            'subject_id' => $ctxA['sub']->id,
        ]);
    }
}
