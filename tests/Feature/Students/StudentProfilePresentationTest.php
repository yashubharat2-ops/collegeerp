<?php

namespace Tests\Feature\Students;

use App\Models\StudentDocument;
use App\Models\StudentQualification;
use Tests\TestCase;

/**
 * What the redesigned profile actually PRINTS.
 *
 * The tab test proves the sections exist; this one proves the values come from
 * the persisted records the Registration Wizard and the enrollment workflow
 * write — that the header agrees with the academic panel, that the current
 * enrollment is chosen by academic period and not by "newest row", that a
 * grade-based qualification never acquires a percentage, and that no private
 * file is referenced by path.
 */
class StudentProfilePresentationTest extends TestCase
{
    use StudentTestHelpers;

    public function test_a_student_without_a_photograph_gets_the_initials_fallback_and_no_image_request(): void
    {
        $college = $this->makeCollege('PROFNP');
        $student = $this->makeStudent($college, ['first_name' => 'Riya', 'last_name' => 'Verma']);
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $response = $this->asCollege($college, $viewer)->get(route('students.show', $student))->assertOk();

        $response->assertSee('RV', false)
            ->assertSee('No photograph', false)
            ->assertDontSee(route('students.photo', $student), false);
    }

    public function test_a_student_with_a_photograph_renders_the_authorized_stream_and_no_storage_path(): void
    {
        $college = $this->makeCollege('PROFPHT');
        $student = $this->makeStudent($college, [
            'photo_path' => sprintf('students/%d/%d/photo.jpg', $college->id, 1),
        ]);
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $response = $this->asCollege($college, $viewer)->get(route('students.show', $student))->assertOk();

        $response->assertSee(route('students.photo', $student), false)
            ->assertDontSee($student->photo_path, false)
            ->assertDontSee('No photograph', false);
    }

    public function test_the_current_enrollment_is_chosen_by_academic_period_not_by_newest_row(): void
    {
        $college = $this->makeCollege('PROFCUR');
        $program = $this->makeProgram($college);

        // The 2025-26 enrollment is created LAST, so a naive "newest row" read
        // would pick it; the 2026-27 academic year is the one containing today.
        $currentYear = $this->makeYear($college, '2026', '2026-27', '2026-06-01', '2027-05-31');
        $pastYear = $this->makeYear($college, '2025', '2025-26', '2025-06-01', '2026-05-31');

        $student = $this->makeStudent($college);
        $currentTerm = $this->makeAcademicTerm($college, $currentYear, 'SEM3', 'Semester 3', 3);

        $current = $this->makeEnrollment($college, $student, $currentYear, $program, [
            'enrollment_number' => 'ENR-CUR',
            'academic_term_id' => $currentTerm->id,
            'roll_number' => 'ROLL-CUR',
        ]);
        $past = $this->makeEnrollment($college, $student, $pastYear, $program, [
            'enrollment_number' => 'ENR-OLD',
            'semester' => 'Semester 1',
            'status' => 'completed',
        ]);

        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $html = $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'enrollments']))
            ->assertOk()
            ->getContent();

        // The current row is the 2026-27 one, marked as such…
        $this->assertMatchesRegularExpression(
            '/sp-row--current">\s*<td[^>]*>ENR-CUR</',
            $html,
            'The current enrollment row must be the 2026-27 one and carry the current-row marker.',
        );

        // …and the older year is still listed, marked as history.
        $this->assertStringContainsString('ENR-OLD', $html);
        $this->assertLessThan(
            strpos($html, 'ENR-OLD'),
            strpos($html, 'ENR-CUR'),
            'Enrollment history must be ordered newest academic year first.',
        );

        // The configured term wins over any free-text label on the row.
        $this->assertStringContainsString('Semester 3', $html);

        // The header's shortcut identifiers describe the CURRENT enrollment.
        $this->assertStringContainsString('ROLL-CUR', $html);
        $this->assertStringNotContainsString('ENR-OLD</dd>', $html);
    }

    public function test_a_grade_based_qualification_never_gains_a_derived_percentage(): void
    {
        $college = $this->makeCollege('PROFQL');
        $student = $this->makeStudent($college);

        StudentQualification::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'level' => StudentQualification::LEVEL_CLASS_12,
            'qualification' => 'Senior Secondary',
            'examination_year' => 2026,
            'board_university' => 'CBSE',
            'institution' => 'Delhi Public School',
            'marks_obtained' => 420,
            'max_marks' => 500,
            'grade' => 'A1',
            'result_status' => 'pass',
        ]);

        StudentQualification::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'level' => StudentQualification::LEVEL_CLASS_10,
            'qualification' => 'Secondary',
            'examination_year' => 2024,
            'board_university' => 'CBSE',
            'institution' => 'Delhi Public School',
            'marks_obtained' => 400,
            'max_marks' => 500,
            'result_status' => 'pass',
        ]);

        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $html = $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'qualifications']))
            ->assertOk()
            ->getContent();

        // The marks-based row may show its arithmetic percentage…
        $this->assertStringContainsString('80.00%', $html);

        // …but the graded row must not: 420/500 must never appear as 84.00%.
        $this->assertStringNotContainsString('84.00%', $html);
        $this->assertStringContainsString('A1', $html);
        $this->assertStringContainsString('CBSE', $html);
    }

    public function test_documents_use_authorized_routes_and_repeat_the_stored_verification_status(): void
    {
        $college = $this->makeCollege('PROFDOC');
        $student = $this->makeStudent($college);
        $documentType = $this->makeDocumentType($college);

        $image = StudentDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'title' => 'Aadhaar scan',
            'file_path' => sprintf('students/%d/%d/scan.png', $college->id, $student->id),
            'original_filename' => 'scan.png',
            'mime_type' => 'image/png',
            'file_size' => 20480,
            'verification_status' => 'pending',
        ]);

        $pdf = StudentDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'title' => 'Transfer certificate',
            'file_path' => sprintf('students/%d/%d/tc.pdf', $college->id, $student->id),
            'original_filename' => 'tc.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 4096,
            'verification_status' => 'verified',
        ]);

        $viewer = $this->makeUserWithPermissions($college, ['students.view', 'student_documents.view']);

        $html = $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'documents']))
            ->assertOk()
            ->getContent();

        // Images are previewed through the authorized action, never by path.
        $this->assertStringContainsString(route('student-documents.preview', $image), $html);
        $this->assertStringNotContainsString($image->file_path, $html);
        $this->assertStringNotContainsString($pdf->file_path, $html);

        // Non-images get a file-type tile and a download link instead.
        $this->assertStringContainsString(route('student-documents.download', $pdf), $html);
        $this->assertStringContainsString('PDF', $html);

        // The stored statuses are repeated, not upgraded.
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('Verified', $html);
        $this->assertStringContainsString('1 verified', $html);
        $this->assertStringContainsString('1 pending', $html);
    }

    public function test_personal_information_repeats_the_registration_fields_and_masks_identity_numbers(): void
    {
        $college = $this->makeCollege('PROFPI');
        $student = $this->makeStudent($college, [
            'middle_name' => 'Kumari',
            'phone' => '9876500001',
            'alternate_phone' => '9876500002',
            'email' => 'riya.verma@example.test',
            'gender' => 'female',
            'category' => 'obc',
            'address_line_1' => '12 MG Road',
            'city' => 'Bhopal',
            'state' => 'Madhya Pradesh',
            'postal_code' => '462001',
            'father_name' => 'Ramesh Verma',
            'mother_name' => 'Sunita Verma',
            'guardian_name' => 'Ramesh Verma',
            'guardian_relation' => 'father',
            'aadhaar_number' => '123456789012',
        ]);

        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $html = $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'personal']))
            ->assertOk()
            ->getContent();

        foreach ([
            'Riya Kumari Verma',
            '12 MG Road, Bhopal, Madhya Pradesh, 462001',
            'riya.verma@example.test',
            '9876500001',
            '9876500002',
            'Ramesh Verma',
            'Sunita Verma',
            'OBC',
            'Father',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // Masked, never the full number, and never in a URL.
        $this->assertStringContainsString('1234', $html);
        $this->assertStringNotContainsString('123456789012', $html);
    }

    public function test_a_pending_registration_is_labelled_pending_and_has_no_active_enrollment(): void
    {
        $college = $this->makeCollege('PROFPEN');
        $student = $this->makeStudent($college, [
            'registration_status' => \App\Models\Student::REGISTRATION_PENDING,
        ]);

        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'academic']))
            ->assertOk()
            ->assertSee('Pending approval', false)
            ->assertSee('awaiting approval', false)
            ->assertDontSee('Current enrollment', false);
    }
}
