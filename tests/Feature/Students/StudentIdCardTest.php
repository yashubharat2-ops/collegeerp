<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Student ID cards.
 *
 * The card is a generated view of Student + StudentEnrollment data: these tests
 * pin the authorization split (view vs generate), the data it renders, tenant
 * isolation, and the fact that generating a card persists nothing except an
 * audit entry.
 */
class StudentIdCardTest extends TestCase
{
    use StudentTestHelpers;

    public function test_index_requires_the_view_permission(): void
    {
        $college = $this->makeCollege('IDCARDX');
        $nobody = $this->makeUserWithPermissions($college, []);
        $viewer = $this->makeUserWithPermissions($college, ['student_id_cards.view']);

        $this->asCollege($college, $nobody)->get(route('student-id-cards.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('student-id-cards.index'))->assertOk()->assertSee('Student ID Cards');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student-id-cards.index'))->assertRedirect(route('login'));
    }

    public function test_generating_a_card_requires_the_generate_permission(): void
    {
        $college = $this->makeCollege('IDCARDP');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['student_id_cards.view', 'students.view']);
        $generator = $this->makeUserWithPermissions($college, ['student_id_cards.view', 'student_id_cards.generate', 'students.view']);

        $this->asCollege($college, $viewer)->get(route('student-id-cards.show', $student))->assertForbidden();
        $this->asCollege($college, $generator)->get(route('student-id-cards.show', $student))->assertOk();
    }

    public function test_card_shows_student_and_enrollment_data(): void
    {
        $college = $this->makeCollege('IDCARDD');
        $generator = $this->makeUserWithPermissions($college, ['student_id_cards.generate', 'students.view']);
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college, 'BCOM');
        $campus = $this->makeCampus($college, 'CITY');
        $section = $this->makeSection($college, $year, $program, 'B', $campus);
        $student = $this->makeStudent($college, [
            'student_number' => 'STU-CARD-1',
            'first_name' => 'Kavya',
            'last_name' => 'Nair',
            'phone' => '9812345670',
            'email' => 'kavya@example.test',
        ]);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-CARD-1',
            'section_id' => $section->id,
        ]);

        $response = $this->asCollege($college, $generator)
            ->get(route('student-id-cards.show', $student))
            ->assertOk();

        $response->assertSee($college->name);
        $response->assertSee('CITY Campus');
        $response->assertSee('Kavya Nair');
        $response->assertSee('STU-CARD-1');
        $response->assertSee('ENR-CARD-1');
        $response->assertSee('2026-27');
        $response->assertSee('Section B');
        $response->assertSee('9812345670');
        // Validity follows the academic year, and the verification payload is present.
        $response->assertSee('31 May 2027');
        $response->assertSee($college->code.'|STU-CARD-1|ENR-CARD-1');

        // Nothing is persisted by generating a card except the audit entry.
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_id_card.generated', 'college_id' => $college->id]);
    }

    public function test_card_falls_back_to_the_latest_enrollment_when_none_is_active(): void
    {
        $college = $this->makeCollege('IDCARDF');
        $generator = $this->makeUserWithPermissions($college, ['student_id_cards.generate', 'students.view']);
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college, ['student_number' => 'STU-CARD-2']);
        $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-COMPLETED',
            'status' => 'completed',
        ]);

        $this->asCollege($college, $generator)
            ->get(route('student-id-cards.show', $student))
            ->assertOk()
            ->assertSee('ENR-COMPLETED');
    }

    public function test_card_is_scoped_to_the_active_college(): void
    {
        $collegeA = $this->makeCollege('IDCARDTA');
        $collegeB = $this->makeCollege('IDCARDTB');
        $foreign = $this->makeStudent($collegeB, ['student_number' => 'STU-CARD-FRG']);
        $generatorA = $this->makeUserWithPermissions($collegeA, ['student_id_cards.generate', 'student_id_cards.view', 'students.view']);

        $this->asCollege($collegeA, $generatorA)->get(route('student-id-cards.show', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $generatorA)
            ->get(route('student-id-cards.index'))
            ->assertOk()
            ->assertDontSee('STU-CARD-FRG');
    }

    public function test_index_filters_by_academic_year_and_section(): void
    {
        $college = $this->makeCollege('IDCARDFL');
        $viewer = $this->makeUserWithPermissions($college, ['student_id_cards.view']);
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college);
        $sectionA = $this->makeSection($college, $year, $program, 'A');

        $inSection = $this->makeStudent($college, ['student_number' => 'STU-SEC-A', 'first_name' => 'InSection']);
        $noEnrollment = $this->makeStudent($college, ['student_number' => 'STU-NOENR', 'first_name' => 'NoEnrollment']);
        $this->makeEnrollment($college, $inSection, $year, $program, ['section_id' => $sectionA->id]);

        $this->asCollege($college, $viewer)
            ->get(route('student-id-cards.index', ['section_id' => $sectionA->id]))
            ->assertSee('STU-SEC-A')
            ->assertDontSee('STU-NOENR');

        $this->asCollege($college, $viewer)
            ->get(route('student-id-cards.index', ['academic_year_id' => $year->id]))
            ->assertSee('STU-SEC-A')
            ->assertDontSee('STU-NOENR');

        $this->asCollege($college, $viewer)
            ->get(route('student-id-cards.index'))
            ->assertSee('STU-SEC-A')
            ->assertSee('STU-NOENR');
    }
}
