<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * The semester / year of study on the New and Edit Enrollment screens.
 *
 * These tests pin the part of the unified academic workflow that lives OUTSIDE
 * the wizard: the level is chosen from the configured terms of the enrollment's
 * own academic year (never free text, never another year's term, never another
 * college's), the label every other screen prints comes from the configured term,
 * and the dependent selects put Section after Semester.
 */
class StudentEnrollmentAcademicContextTest extends TestCase
{
    use StudentTestHelpers;

    public function test_the_create_form_offers_the_configured_term_after_the_programme_and_before_the_section(): void
    {
        $college = $this->makeCollege('ENCTX1');
        $admin = $this->makeUserWithPermissions($college, [
            'student_enrollments.view', 'student_enrollments.create', 'students.view',
        ]);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $this->makeAcademicTerm($college, $year, 'SEM3', 'Semester 3', 3);
        $this->makeSection($college, $year, $program, 'A');

        $html = $this->asCollege($college, $admin)
            ->get(route('student-enrollments.create'))
            ->assertOk()
            ->getContent();

        // The canonical order: year → programme → semester/year of study → section.
        $order = [
            'id="academic_year_id"',
            'id="program_id"',
            'id="academic_term_id"',
            'id="section_id"',
        ];

        $previous = -1;

        foreach ($order as $needle) {
            $position = strpos($html, $needle);
            $this->assertNotFalse($position, "\"{$needle}\" must render on the create form.");
            $this->assertGreaterThan($previous, $position, "\"{$needle}\" is out of the canonical academic order.");
            $previous = $position;
        }

        // The list is the configured master data, and it carries the year it
        // belongs to so the script can narrow it when the year changes.
        $this->assertStringContainsString('Semester 3', $html);
        $this->assertStringContainsString('data-academic-year-id="'.$year->id.'"', $html);
        $this->assertStringContainsString('id="section_id"', $html);
        $this->assertStringContainsString('data-program-id="'.$program->id.'"', $html);
    }

    public function test_the_edit_form_offers_only_the_enrollments_own_academic_year_terms(): void
    {
        $college = $this->makeCollege('ENCTX2');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $student = $this->makeStudent($college);

        $year = $this->makeYear($college);
        $nextYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');

        $ownTerm = $this->makeAcademicTerm($college, $year, 'SEM3', 'Semester 3', 3);
        $this->makeAcademicTerm($college, $nextYear, 'SEM1', 'Semester 1', 1);

        $enrollment = $this->makeEnrollment($college, $student, $year, null, [
            'academic_term_id' => $ownTerm->id,
        ]);

        $html = $this->asCollege($college, $admin)
            ->get(route('student-enrollments.edit', $enrollment))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Semester 3', $html);
        $this->assertStringNotContainsString('Semester 1', $html, "Another academic year's terms must not be offered on the edit form.");
        $this->assertStringNotContainsString('data-academic-year-id="'.$nextYear->id.'"', $html);
    }

    public function test_a_term_from_another_academic_year_is_rejected_when_enrolling(): void
    {
        $college = $this->makeCollege('ENCTX3');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);

        $year = $this->makeYear($college);
        $nextYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $nextTerm = $this->makeAcademicTerm($college, $nextYear, 'SEM1', 'Semester 1', 1);

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $nextTerm->id,
            'status' => 'active',
        ])->assertSessionHasErrors(['academic_term_id']);

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());
    }

    public function test_a_term_from_another_college_is_rejected_when_enrolling(): void
    {
        $college = $this->makeCollege('ENCTX4');
        $other = $this->makeCollege('ENCTX4B');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);

        $year = $this->makeYear($college);
        $otherYear = $this->makeYear($other);
        $foreignTerm = $this->makeAcademicTerm($other, $otherYear, 'SEM1', 'Semester 1', 1);

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $foreignTerm->id,
            'status' => 'active',
        ])->assertSessionHasErrors(['academic_term_id']);
    }

    public function test_the_configured_term_labels_the_enrollment_on_the_list_and_in_the_model(): void
    {
        $college = $this->makeCollege('ENCTX5');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);

        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $term = $this->makeAcademicTerm($college, $year, 'SEM5', 'Semester 5', 5);

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'academic_term_id' => $term->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->sole();

        $this->assertSame($term->id, (int) $enrollment->academic_term_id);
        $this->assertSame('Semester 5', $enrollment->semester, 'The stored label follows the configured term.');
        $this->assertSame('Semester 5', $enrollment->semesterLabel());

        $this->asCollege($college, $admin)
            ->get(route('student-enrollments.index'))
            ->assertOk()
            ->assertSee('Semester / year', false)
            ->assertSee('Semester 5', false);
    }

    public function test_an_enrollment_without_a_configured_term_still_shows_its_stored_label(): void
    {
        $college = $this->makeCollege('ENCTX6');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);

        // A legacy row: no term configured for that year, only the free-text label.
        $enrollment = $this->makeEnrollment($college, $student, $year, null, ['semester' => '2']);

        $this->assertSame('2', $enrollment->semesterLabel());

        $this->asCollege($college, $admin)
            ->get(route('student-enrollments.index'))
            ->assertOk()
            ->assertSee('2', false);
    }
}
