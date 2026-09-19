<?php

namespace Tests\Feature\Students;

use App\Models\StudentAcademicRecord;
use Tests\TestCase;

/**
 * Academic records: CRUD, the one-live-record-per-period rule, and the
 * immutability/tenant rules that keep the progression ledger trustworthy.
 */
class StudentAcademicRecordManagementTest extends TestCase
{
    use StudentTestHelpers;

    private function payload(int $studentId, int $yearId, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $studentId,
            'academic_year_id' => $yearId,
            'academic_status' => 'enrolled',
            'promotion_status' => 'pending',
            'completion_status' => 'pending',
            'remarks' => 'Progression entry',
        ], $overrides);
    }

    public function test_authorized_user_can_view_index(): void
    {
        $college = $this->makeCollege('ARIDX');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.view']);
        $student = $this->makeStudent($college, ['first_name' => 'Arjun']);
        $year = $this->makeYear($college);
        $this->makeAcademicRecord($college, $student, $year);

        $this->asCollege($college, $admin)
            ->get(route('student-academic-records.index'))
            ->assertOk()
            ->assertSee($student->student_number)
            ->assertSee('2026-27');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student-academic-records.index'))->assertRedirect(route('login'));
    }

    public function test_each_permission_is_required_separately(): void
    {
        $college = $this->makeCollege('ARPERM');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $record = $this->makeAcademicRecord($college, $student, $year);
        $viewer = $this->makeUserWithPermissions($college, ['student_academic_records.view']);
        $nobody = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $viewer)->get(route('student-academic-records.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('student-academic-records.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('student-academic-records.store'), $this->payload($student->id, $year->id))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('student-academic-records.edit', $record))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('student-academic-records.update', $record), $this->payload($student->id, $year->id))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('student-academic-records.destroy', $record))->assertForbidden();

        $this->asCollege($college, $nobody)->get(route('student-academic-records.index'))->assertForbidden();
    }

    public function test_authorized_user_can_create_academic_record(): void
    {
        $college = $this->makeCollege('ARCR');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.view', 'student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $term = $this->makeAcademicTerm($college, $year);
        $section = $this->makeSection($college, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, [
                'academic_term_id' => $term->id,
                'program_id' => $program->id,
                'section_id' => $section->id,
                'academic_status' => 'passed',
                'promotion_status' => 'promoted',
                'completion_status' => 'incomplete',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-academic-records.index'));

        $record = StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->assertNotNull($record);
        $this->assertSame($student->id, $record->student_id);
        $this->assertSame($year->id, $record->academic_year_id);
        $this->assertSame($term->id, $record->academic_term_id);
        $this->assertSame($program->id, $record->program_id);
        $this->assertSame($section->id, $record->section_id);
        $this->assertSame('passed', $record->academic_status);
        $this->assertSame('promoted', $record->promotion_status);
        $this->assertSame($college->id, $record->college_id);
        $this->assertSame('2026-27 · Semester 1', $record->periodLabel());

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_academic_record.created', 'college_id' => $college->id]);
    }

    public function test_only_one_live_record_per_student_year_and_term(): void
    {
        $college = $this->makeCollege('ARDUP');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $term = $this->makeAcademicTerm($college, $year);

        $payload = $this->payload($student->id, $year->id, ['academic_term_id' => $term->id]);

        $this->asCollege($college, $admin)->post(route('student-academic-records.store'), $payload)->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $payload)
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(1, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_different_term_of_the_same_year_is_allowed(): void
    {
        $college = $this->makeCollege('ARTWO');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $term1 = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1', 1);
        $term2 = $this->makeAcademicTerm($college, $year, 'SEM2', 'Semester 2', 2);

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, ['academic_term_id' => $term1->id]))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, ['academic_term_id' => $term2->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_soft_deleted_record_does_not_block_re_entry(): void
    {
        $college = $this->makeCollege('ARSD');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);

        $payload = $this->payload($student->id, $year->id);

        $this->asCollege($college, $admin)->post(route('student-academic-records.store'), $payload)->assertSessionHasNoErrors();

        $record = StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $record->delete();
        $this->assertNotNull($record->fresh()->deleted_at);

        $this->asCollege($college, $admin)->post(route('student-academic-records.store'), $payload)->assertSessionHasNoErrors();

        $all = StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->get();
        $this->assertCount(2, $all);
        $this->assertSame(1, $all->whereNull('deleted_at')->count(), 'Exactly one live record.');
        $this->assertSame(1, $all->whereNotNull('deleted_at')->count(), 'History is preserved as a soft-deleted row.');
    }

    public function test_admin_can_update_record_but_student_is_immutable(): void
    {
        $college = $this->makeCollege('ARUP');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.view', 'student_academic_records.update']);
        $student = $this->makeStudent($college);
        $other = $this->makeStudent($college, ['student_number' => 'STU-OTHER', 'first_name' => 'Other']);
        $year = $this->makeYear($college);
        $record = $this->makeAcademicRecord($college, $student, $year);

        $this->asCollege($college, $admin)
            ->put(route('student-academic-records.update', $record), $this->payload($other->id, $year->id, [
                'academic_status' => 'passed',
                'promotion_status' => 'promoted',
                'completion_status' => 'completed',
                'remarks' => 'Updated',
            ]), ['Referer' => route('student-academic-records.edit', $record)])
            ->assertSessionHas('success');

        $record->refresh();
        $this->assertSame($student->id, $record->student_id, 'student_id must never be re-pointed.');
        $this->assertSame('passed', $record->academic_status);
        $this->assertSame('promoted', $record->promotion_status);
        $this->assertSame('completed', $record->completion_status);
        $this->assertSame('Updated', $record->remarks);

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_academic_record.updated']);
    }

    public function test_validation_rejects_missing_and_invalid_values(): void
    {
        $college = $this->makeCollege('ARVAL');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), ['academic_status' => 'enrolled'])
            ->assertSessionHasErrors(['student_id', 'academic_year_id', 'promotion_status', 'completion_status']);

        $this->asCollege($college, $admin)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, ['academic_status' => 'not-a-status']))
            ->assertSessionHasErrors('academic_status');

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_college_id_from_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('ARTNA');
        $collegeB = $this->makeCollege('ARTNB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_academic_records.create']);
        $student = $this->makeStudent($collegeA);
        $year = $this->makeYear($collegeA);

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-academic-records.store'), $this->payload($student->id, $year->id, ['college_id' => $collegeB->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
        $this->assertSame(1, StudentAcademicRecord::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_index_filters_by_student_and_academic_status(): void
    {
        $college = $this->makeCollege('ARFLT');
        $admin = $this->makeUserWithPermissions($college, ['student_academic_records.view']);
        $year = $this->makeYear($college);
        $first = $this->makeStudent($college, ['student_number' => 'STU-FLT-A', 'first_name' => 'Alpha']);
        $second = $this->makeStudent($college, ['student_number' => 'STU-FLT-B', 'first_name' => 'Beta']);
        $this->makeAcademicRecord($college, $first, $year, ['academic_status' => 'passed']);
        $this->makeAcademicRecord($college, $second, $year, ['academic_status' => 'failed']);

        $this->asCollege($college, $admin)
            ->get(route('student-academic-records.index', ['student_id' => $first->id]))
            ->assertSee('STU-FLT-A')->assertDontSee('STU-FLT-B');

        $this->asCollege($college, $admin)
            ->get(route('student-academic-records.index', ['academic_status' => 'failed']))
            ->assertSee('STU-FLT-B')->assertDontSee('STU-FLT-A');
    }
}
