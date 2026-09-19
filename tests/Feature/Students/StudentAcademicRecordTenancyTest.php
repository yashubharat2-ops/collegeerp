<?php

namespace Tests\Feature\Students;

use App\Models\StudentAcademicRecord;
use Tests\TestCase;

/**
 * Tenant isolation and contextual validation for academic records.
 *
 * Cross-college reads are 404 (never a 403 that would confirm the id exists),
 * and every foreign key must belong to the active college AND be contextually
 * valid (term of the chosen year, section of the chosen year/program,
 * enrollment of the chosen student).
 */
class StudentAcademicRecordTenancyTest extends TestCase
{
    use StudentTestHelpers;

    private function payload(int $studentId, int $yearId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'academic_status' => 'enrolled',
            'promotion_status' => 'not_applicable',
            'completion_status' => 'pending',
        ], $overrides);
    }

    public function test_index_only_shows_records_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('ARTA');
        $collegeB = $this->makeCollege('ARTB');
        $yearA = $this->makeYear($collegeA);
        $yearB = $this->makeYear($collegeB);
        $studentA = $this->makeStudent($collegeA, ['student_number' => 'STU-ARA']);
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-ARB']);
        $this->makeAcademicRecord($collegeA, $studentA, $yearA);
        $this->makeAcademicRecord($collegeB, $studentB, $yearB);
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_academic_records.view']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('student-academic-records.index'))
            ->assertSee('STU-ARA')
            ->assertDontSee('STU-ARB');
    }

    public function test_cross_college_record_cannot_be_read_updated_or_deleted(): void
    {
        $collegeA = $this->makeCollege('ARXA');
        $collegeB = $this->makeCollege('ARXB');
        $yearB = $this->makeYear($collegeB);
        $studentB = $this->makeStudent($collegeB);
        $foreign = $this->makeAcademicRecord($collegeB, $studentB, $yearB, ['remarks' => 'Foreign record']);
        $adminA = $this->makeUserWithPermissions($collegeA, [
            'student_academic_records.view',
            'student_academic_records.update',
            'student_academic_records.delete',
        ]);
        $yearA = $this->makeYear($collegeA);
        $studentA = $this->makeStudent($collegeA);

        $this->asCollege($collegeA, $adminA)->get(route('student-academic-records.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->put(route('student-academic-records.update', $foreign), $this->payload($studentA->id, $yearA->id), ['Referer' => route('student-academic-records.index')])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->delete(route('student-academic-records.destroy', $foreign), [], ['Referer' => route('student-academic-records.index')])
            ->assertNotFound();

        $this->assertSame('Foreign record', $foreign->fresh()->remarks);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_foreign_student_and_year_are_rejected_on_create(): void
    {
        $collegeA = $this->makeCollege('ARFA');
        $collegeB = $this->makeCollege('ARFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_academic_records.create']);
        $yearA = $this->makeYear($collegeA);
        $studentB = $this->makeStudent($collegeB);
        $yearB = $this->makeYear($collegeB);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-academic-records.store'), $this->payload($studentB->id, $yearA->id))
            ->assertSessionHasErrors('student_id');

        $studentA = $this->makeStudent($collegeA);
        $this->asCollege($collegeA, $adminA)
            ->post(route('student-academic-records.store'), $this->payload($studentA->id, $yearB->id))
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->count());
    }

    public function test_term_must_belong_to_the_selected_academic_year(): void
    {
        $college = $this->makeCollege('ARCTX1');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $nextYear = $this->makeNextYear($college, '2027');
        $otherTerm = $this->makeAcademicTerm($college, $nextYear, 'SEM1', 'Semester 1');

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, ['academic_term_id' => $otherTerm->id]))
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_section_must_match_the_selected_year_and_program(): void
    {
        $college = $this->makeCollege('ARCTX2');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BCOM');
        $otherProgram = $this->makeProgram($college, 'BSC');
        // Section belongs to (year, otherProgram) — not to (year, program).
        $wrongProgramSection = $this->makeSection($college, $year, $otherProgram, 'B');

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, [
                'program_id' => $program->id,
                'section_id' => $wrongProgramSection->id,
            ]))
            ->assertSessionHasErrors('section_id');

        // A section from another academic year is rejected too.
        $nextYear = $this->makeNextYear($college, '2027');
        $wrongYearSection = $this->makeSection($college, $nextYear, $program, 'C');

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, [
                'program_id' => $program->id,
                'section_id' => $wrongYearSection->id,
            ]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_enrollment_must_belong_to_the_selected_student(): void
    {
        $college = $this->makeCollege('ARCTX3');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $year = $this->makeYear($college);
        $student = $this->makeStudent($college);
        $other = $this->makeStudent($college, ['student_number' => 'STU-AROTH']);
        $otherEnrollment = $this->makeEnrollment($college, $other, $year);

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, ['enrollment_id' => $otherEnrollment->id]))
            ->assertSessionHasErrors('enrollment_id');

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_foreign_section_and_term_are_rejected(): void
    {
        $collegeA = $this->makeCollege('ARFSA');
        $collegeB = $this->makeCollege('ARFSB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_academic_records.create']);
        $yearA = $this->makeYear($collegeA, '2026');
        $studentA = $this->makeStudent($collegeA);
        $programA = $this->makeProgram($collegeA, 'BCOM');

        // College B happens to own a section with the same year/program code.
        $yearB = $this->makeYear($collegeB, '2026');
        $programB = $this->makeProgram($collegeB, 'BCOM');
        $foreignSection = $this->makeSection($collegeB, $yearB, $programB, 'A');
        $foreignTerm = $this->makeAcademicTerm($collegeB, $yearB, 'SEM1', 'Semester 1');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-academic-records.store'), $this->payload($studentA->id, $yearA->id, [
                'program_id' => $programA->id,
                'section_id' => $foreignSection->id,
            ]))
            ->assertSessionHasErrors('section_id');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-academic-records.store'), $this->payload($studentA->id, $yearA->id, [
                'academic_term_id' => $foreignTerm->id,
            ]))
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }
}
