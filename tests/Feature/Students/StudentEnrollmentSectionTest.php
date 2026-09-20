<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Section / Batch on a student enrollment.
 *
 * Sections are Platform master data, so an enrollment only ever REFERENCES one —
 * and the reference must be contextually valid: same college, same academic
 * year and (when a program is chosen) same program. The server, not the
 * browser-side filtering, is what enforces that.
 */
class StudentEnrollmentSectionTest extends TestCase
{
    use StudentTestHelpers;

    private function payload(int $studentId, int $yearId, ?int $programId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'program_id' => $programId,
            'enrollment_date' => '2026-06-10',
            'status' => 'active',
        ], $overrides);
    }

    public function test_the_enrollment_form_offers_sections_with_their_context(): void
    {
        $college = $this->makeCollege('SECUI');
        $creator = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'A');

        $this->asCollege($college, $creator)
            ->get(route('student-enrollments.create'))
            ->assertOk()
            ->assertSee('name="section_id"', false)
            ->assertSee('Section A', false)
            // The client-side filter needs the year/program context per option.
            ->assertSee('data-academic-year-id="'.$year->id.'"', false)
            ->assertSee('data-program-id="'.$program->id.'"', false)
            ->assertSee('value="'.$section->id.'"', false);
    }

    public function test_a_contextually_valid_section_is_stored(): void
    {
        $college = $this->makeCollege('SECOK');
        // The edit form is update-gated, so the actor who stores an enrollment
        // must hold student_enrollments.update to open it.
        $creator = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'B');
        $student = $this->makeStudent($college, ['student_number' => 'STU-SEC-1']);

        $this->asCollege($college, $creator)
            ->post(route('student-enrollments.store'), $this->payload($student->id, $year->id, $program->id, ['section_id' => $section->id]))
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->assertNotNull($enrollment);
        $this->assertSame($section->id, $enrollment->section_id);

        $this->asCollege($college, $creator)
            ->get(route('student-enrollments.index'))
            ->assertOk()
            ->assertSee('Section B', false);

        // The edit form pre-selects the stored section.
        $edit = $this->asCollege($college, $creator)
            ->get(route('student-enrollments.edit', $enrollment))
            ->assertOk()
            ->assertSee('name="section_id"', false);

        // Laravel's @selected directive emits the bare `selected` attribute,
        // not selected="selected", so the regex matches the attribute itself.
        $this->assertMatchesRegularExpression(
            '/value="'.$section->id.'"[^>]*\sselected[\s>]/',
            $edit->getContent(),
            'The stored section must be pre-selected in the edit form.'
        );
    }

    public function test_section_is_optional(): void
    {
        $college = $this->makeCollege('SECOPT');
        $creator = $this->makeUserWithPermissions($college, ['student_enrollments.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);

        $this->asCollege($college, $creator)
            ->post(route('student-enrollments.store'), $this->payload($student->id, $year->id, $program->id, ['section_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first()->section_id);
    }

    public function test_a_section_of_another_program_or_year_is_rejected(): void
    {
        $college = $this->makeCollege('SECCTX');
        $creator = $this->makeUserWithPermissions($college, ['student_enrollments.create']);
        $year = $this->makeYear($college, '2026', '2026-27');
        $nextYear = $this->makeNextYear($college, '2027');
        $bsc = $this->makeProgram($college, 'BSC');
        $bcom = $this->makeProgram($college, 'BCOM');
        $student = $this->makeStudent($college);

        $sectionOfOtherProgram = $this->makeSection($college, $year, $bcom, 'C');
        $sectionOfOtherYear = $this->makeSection($college, $nextYear, $bsc, 'A');

        $this->asCollege($college, $creator)
            ->post(route('student-enrollments.store'), $this->payload($student->id, $year->id, $bsc->id, ['section_id' => $sectionOfOtherProgram->id]))
            ->assertSessionHasErrors('section_id');

        $this->asCollege($college, $creator)
            ->post(route('student-enrollments.store'), $this->payload($student->id, $year->id, $bsc->id, ['section_id' => $sectionOfOtherYear->id]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_another_colleges_section_is_rejected(): void
    {
        $collegeA = $this->makeCollege('SECFA');
        $collegeB = $this->makeCollege('SECFB');
        $creatorA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);

        // Same codes in both colleges, so only the tenant check can catch this.
        $yearA = $this->makeYear($collegeA);
        $programA = $this->makeProgram($collegeA, 'BSC');
        $studentA = $this->makeStudent($collegeA);

        $yearB = $this->makeYear($collegeB);
        $programB = $this->makeProgram($collegeB, 'BSC');
        $foreignSection = $this->makeSection($collegeB, $yearB, $programB, 'A');

        $this->asCollege($collegeA, $creatorA)
            ->post(route('student-enrollments.store'), $this->payload($studentA->id, $yearA->id, $programA->id, ['section_id' => $foreignSection->id]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_section_can_be_updated_and_cannot_be_swapped_for_a_foreign_one(): void
    {
        $college = $this->makeCollege('SECUPD');
        $collegeB = $this->makeCollege('SECUPDB');
        $editor = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $sectionA = $this->makeSection($college, $year, $program, 'A');
        $sectionB = $this->makeSection($college, $year, $program, 'B');
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['section_id' => $sectionA->id]);

        $foreign = $this->makeSection($collegeB, $this->makeYear($collegeB), $this->makeProgram($collegeB, 'BSC'), 'A');

        $this->asCollege($college, $editor)
            ->put(route('student-enrollments.update', $enrollment), $this->payload($student->id, $year->id, $program->id, ['section_id' => $sectionB->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($sectionB->id, $enrollment->fresh()->section_id);

        $this->asCollege($college, $editor)
            ->put(route('student-enrollments.update', $enrollment), $this->payload($student->id, $year->id, $program->id, ['section_id' => $foreign->id]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame($sectionB->id, $enrollment->fresh()->section_id);
    }

    public function test_a_spoofed_college_id_does_not_change_where_the_enrollment_lands(): void
    {
        $collegeA = $this->makeCollege('SECSAFE');
        $collegeB = $this->makeCollege('SECSAFB');
        $creatorA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $yearA = $this->makeYear($collegeA);
        $programA = $this->makeProgram($collegeA);
        $sectionA = $this->makeSection($collegeA, $yearA, $programA, 'A');
        $studentA = $this->makeStudent($collegeA);

        $this->asCollege($collegeA, $creatorA)
            ->post(route('student-enrollments.store'), $this->payload($studentA->id, $yearA->id, $programA->id, [
                'section_id' => $sectionA->id,
                'college_id' => $collegeB->id,
            ]))
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $studentA->id)->first();

        $this->assertSame($collegeA->id, $enrollment->college_id);
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }
}
