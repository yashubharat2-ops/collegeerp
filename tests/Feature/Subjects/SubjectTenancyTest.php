<?php

namespace Tests\Feature\Subjects;

use App\Models\{College, Department, Subject};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SubjectTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_subjects_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('SBIA');
        $collegeB = $this->makeCollege('SBIB');

        Subject::create(['college_id' => $collegeA->id, 'name' => 'Alpha Subject', 'code' => 'ASUB', 'status' => 'active']);
        Subject::create(['college_id' => $collegeB->id, 'name' => 'Bravo Subject', 'code' => 'BSUB', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['subjects.view']);

        $this->asCollege($collegeA, $adminA)->get(route('subjects.index'))
            ->assertSee('Alpha Subject')
            ->assertDontSee('Bravo Subject');
    }

    public function test_same_code_across_colleges_is_allowed(): void
    {
        $collegeA = $this->makeCollege('SBDA');
        $collegeB = $this->makeCollege('SBDB');

        Subject::create(['college_id' => $collegeA->id, 'name' => 'Calculus I', 'code' => 'MATH101', 'status' => 'active']);

        $adminB = $this->makeUserWithPermissions($collegeB, ['subjects.view', 'subjects.create']);

        $this->asCollege($collegeB, $adminB)
            ->post(route('subjects.store'), [
                'name' => 'Calculus I',
                'code' => 'MATH101',
                'status' => 'active',
            ], ['Referer' => route('subjects.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, Subject::withoutGlobalScopes()->where('code', 'MATH101')->count());
    }

    public function test_cross_college_department_is_rejected(): void
    {
        $collegeA = $this->makeCollege('SBDPA');
        $collegeB = $this->makeCollege('SBDPB');

        $deptB = Department::create(['college_id' => $collegeB->id, 'name' => 'Foreign Dept', 'code' => 'FD', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['subjects.view', 'subjects.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('subjects.store'), [
                'department_id' => $deptB->id,
                'name' => 'Illegal Dept Subject',
                'code' => 'IDS',
                'status' => 'active',
            ], ['Referer' => route('subjects.index')])
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('subjects', ['code' => 'IDS']);
    }

    public function test_cross_college_subject_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('SBOCA');
        $collegeB = $this->makeCollege('SBOCB');

        $foreign = Subject::create(['college_id' => $collegeB->id, 'name' => 'Foreign Subject', 'code' => 'FSUB', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['subjects.view', 'subjects.update', 'subjects.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('subjects.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('subjects.update', $foreign), [
            'name' => 'Hijacked Subject',
            'code' => 'FSUB',
            'status' => 'active',
        ], ['Referer' => route('subjects.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('subjects.destroy', $foreign), [], ['Referer' => route('subjects.index')])->assertNotFound();

        $this->assertSame('Foreign Subject', $foreign->fresh()->name);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SBSPA');
        $collegeB = $this->makeCollege('SBSPB');

        $adminA = $this->makeUserWithPermissions($collegeA, ['subjects.view', 'subjects.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('subjects.store'), [
                'college_id' => $collegeB->id,
                'name' => 'Sneaky Subject',
                'code' => 'SNK-SUB',
                'status' => 'active',
            ], ['Referer' => route('subjects.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subjects', ['college_id' => $collegeA->id, 'code' => 'SNK-SUB']);
        $this->assertDatabaseMissing('subjects', ['college_id' => $collegeB->id, 'code' => 'SNK-SUB']);
    }
}
