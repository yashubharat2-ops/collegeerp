<?php

namespace Tests\Feature\FacultySubjectAssignments;

use App\Models\{AcademicTerm, AcademicYear, AuditLog, Faculty, FacultySubjectAssignment, Program, Section, Subject};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class FacultySubjectAssignmentManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_assignment(): void
    {
        $college = $this->makeCollege('FSAM');
        $admin = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.view', 'faculty_subject_assignments.create']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => 'Sem 1', 'code' => 'SEM1', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'CS', 'code' => 'CS', 'status' => 'active']);
        $section = Section::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'program_id' => $program->id, 'name' => 'Sec A', 'code' => 'A', 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Algorithms', 'code' => 'CS201', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'FAC-01', 'first_name' => 'Donald', 'last_name' => 'Knuth', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $faculty->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'program_id' => $program->id,
                'section_id' => $section->id,
                'status' => 'active',
                'remarks' => 'Lead instructor',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertRedirect(route('faculty-subject-assignments.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('faculty_subject_assignments', [
            'college_id' => $college->id,
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'status' => 'active',
            'remarks' => 'Lead instructor',
        ]);
    }

    public function test_duplicate_active_assignment_in_same_academic_context_is_rejected(): void
    {
        $college = $this->makeCollege('FSAD');
        $admin = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.view', 'faculty_subject_assignments.create', 'faculty_subject_assignments.update']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $term = AcademicTerm::create(['college_id' => $college->id, 'academic_year_id' => $year->id, 'name' => 'Sem 1', 'code' => 'SEM1', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Algorithms', 'code' => 'CS201', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'FAC-01', 'first_name' => 'Donald', 'last_name' => 'Knuth', 'status' => 'active']);

        FacultySubjectAssignment::create([
            'college_id' => $college->id,
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'status' => 'active',
        ]);

        // Duplicate in identical context: rejected
        $this->asCollege($college, $admin)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $faculty->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasErrors('faculty_id');

        // Updating the same assignment preserves validity
        $assignment = FacultySubjectAssignment::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->asCollege($college, $admin)
            ->put(route('faculty-subject-assignments.update', $assignment), [
                'faculty_id' => $faculty->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'status' => 'inactive',
                'remarks' => 'Temporarily inactive',
            ], ['Referer' => route('faculty-subject-assignments.edit', $assignment)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('inactive', $assignment->fresh()->status);
    }

    public function test_soft_deleted_assignment_can_be_recreated(): void
    {
        $college = $this->makeCollege('FSAR');
        $admin = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.view', 'faculty_subject_assignments.create', 'faculty_subject_assignments.delete']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Math', 'code' => 'MTH', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'FAC-02', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'active']);

        $assignment = FacultySubjectAssignment::create([
            'college_id' => $college->id,
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
        ]);

        // Soft delete the existing record
        $this->asCollege($college, $admin)->delete(route('faculty-subject-assignments.destroy', $assignment), [], ['Referer' => route('faculty-subject-assignments.index')]);
        $this->assertSoftDeleted('faculty_subject_assignments', ['id' => $assignment->id]);

        // Recreate the assignment with same details: must succeed
        $this->asCollege($college, $admin)
            ->post(route('faculty-subject-assignments.store'), [
                'faculty_id' => $faculty->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
                'status' => 'active',
            ], ['Referer' => route('faculty-subject-assignments.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, FacultySubjectAssignment::withTrashed()->where('college_id', $college->id)->count());
        $this->assertSame(1, FacultySubjectAssignment::where('college_id', $college->id)->count());
    }

    public function test_audit_logs_record_faculty_subject_assignment_actions(): void
    {
        $college = $this->makeCollege('FSAA');
        $admin = $this->makeUserWithPermissions($college, ['faculty_subject_assignments.view', 'faculty_subject_assignments.create', 'faculty_subject_assignments.update', 'faculty_subject_assignments.delete']);

        $year = AcademicYear::create(['college_id' => $college->id, 'name' => '2026-2027', 'code' => 'AY-2026', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $subject = Subject::create(['college_id' => $college->id, 'name' => 'Physics', 'code' => 'PHY', 'status' => 'active']);
        $faculty = Faculty::create(['college_id' => $college->id, 'employee_code' => 'FAC-03', 'first_name' => 'Marie', 'last_name' => 'Curie', 'status' => 'active']);

        $this->asCollege($college, $admin)->post(route('faculty-subject-assignments.store'), [
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
            'remarks' => 'Initial',
        ], ['Referer' => route('faculty-subject-assignments.index')])->assertSessionHasNoErrors();

        $assign = FacultySubjectAssignment::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();

        $this->asCollege($college, $admin)->put(route('faculty-subject-assignments.update', $assign), [
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
            'remarks' => 'Updated remarks',
        ], ['Referer' => route('faculty-subject-assignments.edit', $assign)])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)->delete(route('faculty-subject-assignments.destroy', $assign), [], ['Referer' => route('faculty-subject-assignments.index')]);

        $base = [
            'college_id' => $college->id,
            'user_id' => $admin->id,
            'subject_type' => FacultySubjectAssignment::class,
            'subject_id' => $assign->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'faculty_subject_assignment.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'faculty_subject_assignment.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'faculty_subject_assignment.deleted']);
    }
}
