<?php

namespace Tests\Feature\ExamSchedules;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Section;
use App\Models\Subject;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ExamScheduleManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    private function createAcademicContext(string $prefix = 'ESM'): array
    {
        $college = $this->makeCollege($prefix);
        $admin = $this->makeUserWithPermissions($college, [
            'examinations.view', 'examinations.create',
            'exam_schedules.view', 'exam_schedules.create', 'exam_schedules.update', 'exam_schedules.delete',
        ]);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => "AY-{$prefix}",
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Semester 1',
            'code' => "SEM1-{$prefix}",
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'Computer Science',
            'code' => "CS-{$prefix}",
            'status' => 'active',
        ]);
        $section = Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => 'Section A',
            'code' => "A-{$prefix}",
            'status' => 'active',
        ]);
        $subject = Subject::create([
            'college_id' => $college->id,
            'name' => 'Operating Systems',
            'code' => "CS301-{$prefix}",
            'status' => 'active',
        ]);
        $faculty = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => "FAC-{$prefix}",
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'status' => 'active',
        ]);
        $campus = Campus::create([
            'college_id' => $college->id,
            'name' => 'Main Campus',
            'code' => "MC-{$prefix}",
            'status' => 'active',
        ]);
        $exam = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'Mid Term 2026',
            'code' => "EXAM-{$prefix}",
            'exam_type' => 'Mid Term',
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-25',
            'status' => 'published',
        ]);

        return compact('college', 'admin', 'year', 'term', 'program', 'section', 'subject', 'faculty', 'campus', 'exam');
    }

    /**
     * Requirement 13: can create valid schedule
     */
    public function test_can_create_valid_schedule(): void
    {
        $ctx = $this->createAcademicContext('ES13');

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'faculty_id' => $ctx['faculty']->id,
                'campus_id' => $ctx['campus']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'room' => 'Hall 101',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
                'remarks' => 'Calculators allowed',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertRedirect(route('exam-schedules.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('exam_schedules', [
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'room' => 'Hall 101',
            'status' => 'scheduled',
        ]);
    }

    /**
     * Requirement 17: section/program mismatch rejected
     */
    public function test_section_program_mismatch_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES17');

        $otherProgram = Program::create([
            'college_id' => $ctx['college']->id,
            'name' => 'Electrical Engineering',
            'code' => 'EE-17',
            'status' => 'active',
        ]);

        // section belongs to CS program, but submitted with EE program
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $otherProgram->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['section_id']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 18: section/academic-year mismatch rejected
     */
    public function test_section_academic_year_mismatch_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES18');

        $otherYear = AcademicYear::create([
            'college_id' => $ctx['college']->id,
            'name' => '2025-2026',
            'code' => 'AY-2025-18',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-05-31',
            'status' => 'active',
        ]);
        $otherSection = Section::create([
            'college_id' => $ctx['college']->id,
            'academic_year_id' => $otherYear->id,
            'program_id' => $ctx['program']->id,
            'name' => 'Old Section',
            'code' => 'OLD-SEC',
            'status' => 'active',
        ]);

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $otherSection->id, // belongs to otherYear
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['section_id']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 19: academic-term/year mismatch rejected
     */
    public function test_academic_term_year_mismatch_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES19');

        $otherYear = AcademicYear::create([
            'college_id' => $ctx['college']->id,
            'name' => '2025-2026',
            'code' => 'AY-2025-19',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-05-31',
            'status' => 'active',
        ]);
        $otherTerm = AcademicTerm::create([
            'college_id' => $ctx['college']->id,
            'academic_year_id' => $otherYear->id,
            'name' => 'Old Term',
            'code' => 'OLD-TERM',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $otherTerm->id, // belongs to otherYear
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['academic_term_id']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 20: passing marks cannot exceed maximum marks
     */
    public function test_passing_marks_cannot_exceed_maximum_marks(): void
    {
        $ctx = $this->createAcademicContext('ES20');

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 50,
                'passing_marks' => 60, // passing > max
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['passing_marks']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 21: end time must be after start time
     */
    public function test_end_time_must_be_after_start_time(): void
    {
        $ctx = $this->createAcademicContext('ES21');

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '12:00',
                'end_time' => '09:00', // end before start
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['end_time']);

        $this->assertSame(0, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 22: duplicate schedule rejected
     */
    public function test_duplicate_schedule_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES22');

        ExamSchedule::create([
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        // Attempt exact duplicate schedule
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['subject_id']);

        $this->assertSame(1, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 23: section time conflict rejected
     */
    public function test_section_time_conflict_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES23');

        $otherSubject = Subject::create([
            'college_id' => $ctx['college']->id,
            'name' => 'Data Structures',
            'code' => 'CS202-23',
            'status' => 'active',
        ]);

        ExamSchedule::create([
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        // Same section overlapping: 10:00 to 13:00 on same date
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $otherSubject->id,
                'exam_date' => '2026-10-16',
                'start_time' => '10:00',
                'end_time' => '13:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['section_id']);

        $this->assertSame(1, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 24: faculty time conflict rejected
     */
    public function test_faculty_time_conflict_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES24');

        $sectionB = Section::create([
            'college_id' => $ctx['college']->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['program']->id,
            'name' => 'Section B',
            'code' => 'B-24',
            'status' => 'active',
        ]);
        $otherSubject = Subject::create([
            'college_id' => $ctx['college']->id,
            'name' => 'Data Structures',
            'code' => 'CS202-24',
            'status' => 'active',
        ]);

        ExamSchedule::create([
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'faculty_id' => $ctx['faculty']->id,
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        // Different section and subject, but same faculty overlapping: 11:00 to 14:00
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $sectionB->id,
                'subject_id' => $otherSubject->id,
                'faculty_id' => $ctx['faculty']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '11:00',
                'end_time' => '14:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['faculty_id']);

        $this->assertSame(1, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 25: room time conflict rejected
     */
    public function test_room_time_conflict_rejected(): void
    {
        $ctx = $this->createAcademicContext('ES25');

        $sectionB = Section::create([
            'college_id' => $ctx['college']->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['program']->id,
            'name' => 'Section B',
            'code' => 'B-25',
            'status' => 'active',
        ]);
        $otherSubject = Subject::create([
            'college_id' => $ctx['college']->id,
            'name' => 'Algorithms',
            'code' => 'CS203-25',
            'status' => 'active',
        ]);

        ExamSchedule::create([
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'room' => 'Hall 201',
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        // Different section/subject, but same room overlapping: 10:00 to 11:30
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $sectionB->id,
                'subject_id' => $otherSubject->id,
                'room' => 'Hall 201',
                'exam_date' => '2026-10-16',
                'start_time' => '10:00',
                'end_time' => '11:30',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasErrors(['room']);

        $this->assertSame(1, ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 26: soft-deleted schedule behavior is correct
     */
    public function test_soft_deleted_schedule_does_not_block_rescheduling(): void
    {
        $ctx = $this->createAcademicContext('ES26');

        $schedule = ExamSchedule::create([
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'room' => 'Hall 303',
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        // Soft delete the schedule
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->delete(route('exam-schedules.destroy', $schedule), [], ['Referer' => route('exam-schedules.index')])
            ->assertRedirect(route('exam-schedules.index'));

        $this->assertSoftDeleted('exam_schedules', ['id' => $schedule->id]);

        // Recreate the identical schedule entry: must succeed without duplicate or conflict error
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'room' => 'Hall 303',
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('exam-schedules.index'));

        $this->assertSame(2, ExamSchedule::withTrashed()->where('college_id', $ctx['college']->id)->count());
        $this->assertSame(1, ExamSchedule::where('college_id', $ctx['college']->id)->count());
    }

    /**
     * Requirement 27: update conflict excludes the current schedule
     */
    public function test_update_conflict_excludes_current_schedule(): void
    {
        $ctx = $this->createAcademicContext('ES27');

        $schedule = ExamSchedule::create([
            'college_id' => $ctx['college']->id,
            'examination_id' => $ctx['exam']->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'faculty_id' => $ctx['faculty']->id,
            'room' => 'Lab 1',
            'exam_date' => '2026-10-16',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'scheduled',
        ]);

        // Updating the schedule with same time slot must NOT conflict with itself
        $this->asCollege($ctx['college'], $ctx['admin'])
            ->put(route('exam-schedules.update', $schedule), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'faculty_id' => $ctx['faculty']->id,
                'room' => 'Lab 1',
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 45,
                'status' => 'scheduled',
                'remarks' => 'Passing marks updated to 45',
            ], ['Referer' => route('exam-schedules.edit', $schedule)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('exam-schedules.index'))
            ->assertSessionHas('success');

        $schedule->refresh();
        $this->assertSame('45.00', (string) $schedule->passing_marks);
        $this->assertSame('Passing marks updated to 45', $schedule->remarks);
    }

    public function test_audit_logs_record_exam_schedule_actions(): void
    {
        $ctx = $this->createAcademicContext('ESAU');

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->post(route('exam-schedules.store'), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'scheduled',
            ], ['Referer' => route('exam-schedules.index')])
            ->assertSessionHasNoErrors();

        $schedule = ExamSchedule::withoutGlobalScopes()->where('college_id', $ctx['college']->id)->firstOrFail();

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->put(route('exam-schedules.update', $schedule), [
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['program']->id,
                'section_id' => $ctx['section']->id,
                'subject_id' => $ctx['subject']->id,
                'exam_date' => '2026-10-16',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'completed',
            ], ['Referer' => route('exam-schedules.edit', $schedule)])
            ->assertSessionHasNoErrors();

        $this->asCollege($ctx['college'], $ctx['admin'])
            ->delete(route('exam-schedules.destroy', $schedule), [], ['Referer' => route('exam-schedules.index')]);

        $base = [
            'college_id' => $ctx['college']->id,
            'user_id' => $ctx['admin']->id,
            'subject_type' => ExamSchedule::class,
            'subject_id' => $schedule->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'exam_schedule.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'exam_schedule.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'exam_schedule.deleted']);
    }
}
