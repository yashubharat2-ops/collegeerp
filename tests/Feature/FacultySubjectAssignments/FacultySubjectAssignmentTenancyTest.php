<?php

namespace Tests\Feature\FacultySubjectAssignments;

use App\Models\{AcademicTerm, AcademicYear, College, Faculty, FacultySubjectAssignment, Program, Section, Subject};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultySubjectAssignmentTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_assignments_of_active_college(): void
    {
        $collegeA = $this->makeCollege('FSIA');
        $collegeB = $this->makeCollege('FSIB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subA = Subject::create(['college_id' => $collegeA->id, 'name' => 'Subject A', 'code' => 'SA', 'status' => 'active']);
        $facA = Faculty::create(['college_id' => $collegeA->id, 'employee_code' => 'FA', 'first_name' => 'Alice', 'last_name' => 'Alpha', 'status' => 'active']);
        FacultySubjectAssignment::create(['college_id' => $collegeA->id, 'faculty_id' => $facA->id, 'subject_id' => $subA->id, 'academic_year_id' => $yearA->id, 'status' => 'active']);

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subB = Subject::create(['college_id' => $collegeB->id, 'name' => 'Subject B', 'code' => 'SB', 'status' => 'active']);
        $facB = Faculty::create(['college_id' => $collegeB->id, 'employee_code' => 'FB', 'first_name' => 'Bob', 'last_name' => 'Bravo', 'status' => 'active']);
        FacultySubjectAssignment::create(['college_id' => $collegeB->id, 'faculty_id' => $facB->id, 'subject_id' => $subB->id, 'academic_year_id' => $yearB->id, 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculty_subject_assignments.view']);

        $this->asCollege($collegeA, $adminA)->get(route('faculty-subject-assignments.index'))
            ->assertSee('Alice Alpha')
            ->assertDontSee('Bob Bravo');
    }

    public function test_cross_college_foreign_keys_are_rejected(): void
    {
        $collegeA = $this->makeCollege('FSXA');
        $collegeB = $this->makeCollege('FSXB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subA = Subject::create(['college_id' => $collegeA->id, 'name' => 'Subject A', 'code' => 'SA', 'status' => 'active']);
        $facA = Faculty::create(['college_id' => $collegeA->id, 'employee_code' => 'FA', 'first_name' => 'Alice', 'last_name' => 'A', 'status' => 'active']);
        $termA = AcademicTerm::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'name' => 'Term A', 'code' => 'TA', 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $progA = Program::create(['college_id' => $collegeA->id, 'name' => 'Prog A', 'code' => 'PA', 'status' => 'active']);
        $secA = Section::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'program_id' => $progA->id, 'name' => 'Sec A', 'code' => 'SA', 'status' => 'active']);

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subB = Subject::create(['college_id' => $collegeB->id, 'name' => 'Subject B', 'code' => 'SB', 'status' => 'active']);
        $facB = Faculty::create(['college_id' => $collegeB->id, 'employee_code' => 'FB', 'first_name' => 'Bob', 'last_name' => 'B', 'status' => 'active']);
        $termB = AcademicTerm::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'name' => 'Term B', 'code' => 'TB', 'type' => 'term', 'sequence' => 1, 'status' => 'active']);
        $progB = Program::create(['college_id' => $collegeB->id, 'name' => 'Prog B', 'code' => 'PB', 'status' => 'active']);
        $secB = Section::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'program_id' => $progB->id, 'name' => 'Sec B', 'code' => 'SB', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculty_subject_assignments.view', 'faculty_subject_assignments.create']);

        // Cross-college faculty rejected
        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $facB->id,
                'subject_id' => $subA->id,
                'academic_year_id' => $yearA->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('faculty_id');

        // Cross-college subject rejected
        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $facA->id,
                'subject_id' => $subB->id,
                'academic_year_id' => $yearA->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('subject_id');

        // Cross-college academic year rejected
        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $facA->id,
                'subject_id' => $subA->id,
                'academic_year_id' => $yearB->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('academic_year_id');

        // Cross-college academic term rejected
        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $facA->id,
                'subject_id' => $subA->id,
                'academic_year_id' => $yearA->id,
                'academic_term_id' => $termB->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('academic_term_id');

        // Cross-college program rejected
        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $facA->id,
                'subject_id' => $subA->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $progB->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('program_id');

        // Cross-college section rejected
        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $facA->id,
                'subject_id' => $subA->id,
                'academic_year_id' => $yearA->id,
                'section_id' => $secB->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, FacultySubjectAssignment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_cross_college_assignment_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('FSCA');
        $collegeB = $this->makeCollege('FSCB');

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subB = Subject::create(['college_id' => $collegeB->id, 'name' => 'Subject B', 'code' => 'SB', 'status' => 'active']);
        $facB = Faculty::create(['college_id' => $collegeB->id, 'employee_code' => 'FB', 'first_name' => 'Foreign', 'last_name' => 'Faculty', 'status' => 'active']);
        $foreign = FacultySubjectAssignment::create(['college_id' => $collegeB->id, 'faculty_id' => $facB->id, 'subject_id' => $subB->id, 'academic_year_id' => $yearB->id, 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculty_subject_assignments.view', 'faculty_subject_assignments.update', 'faculty_subject_assignments.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('faculty-subject-assignments.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('faculty-subject-assignments.update', $foreign), [
            'faculty_id' => $facB->id,
            'subject_id' => $subB->id,
            'academic_year_id' => $yearB->id,
            'status' => 'inactive',
        ], ['Referer' => route('faculty-subject-assignments.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('faculty-subject-assignments.destroy', $foreign), [], ['Referer' => route('faculty-subject-assignments.index')])->assertNotFound();

        $this->assertSame('active', $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('FSPA');
        $collegeB = $this->makeCollege('FSPB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subA = Subject::create(['college_id' => $collegeA->id, 'name' => 'Subject A', 'code' => 'SA', 'status' => 'active']);
        $facA = Faculty::create(['college_id' => $collegeA->id, 'employee_code' => 'FA', 'first_name' => 'Alice', 'last_name' => 'A', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['faculty_subject_assignments.view', 'faculty_subject_assignments.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('faculty-subject-assignments.store'), [
                'college_id' => $collegeB->id,
                'faculty_id' => $facA->id,
                'subject_id' => $subA->id,
                'academic_year_id' => $yearA->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('faculty_subject_assignments', ['college_id' => $collegeA->id, 'faculty_id' => $facA->id, 'subject_id' => $subA->id]);
        $this->assertDatabaseMissing('faculty_subject_assignments', ['college_id' => $collegeB->id, 'faculty_id' => $facA->id]);
    }
}
