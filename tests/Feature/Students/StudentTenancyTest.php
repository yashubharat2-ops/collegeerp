<?php

namespace Tests\Feature\Students;

use App\Models\Student;
use Tests\TestCase;

class StudentTenancyTest extends TestCase
{
    use StudentTestHelpers;

    public function test_list_only_shows_students_of_active_college(): void
    {
        $collegeA = $this->makeCollege('STNA');
        $collegeB = $this->makeCollege('STNB');
        $studentA = $this->makeStudent($collegeA, ['student_number' => 'STU-A']);
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-B']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.view']);

        $this->asCollege($collegeA, $adminA)->get(route('students.index'))
            ->assertSee('STU-A')
            ->assertDontSee('STU-B');
    }

    public function test_cross_college_student_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('STXA');
        $collegeB = $this->makeCollege('STXB');
        $foreign = $this->makeStudent($collegeB, ['student_number' => 'STU-FRG']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.view', 'students.update', 'students.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('students.show', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->get(route('students.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->put(route('students.update', $foreign), ['first_name' => 'Hacked', 'status' => 'active'], ['Referer' => route('students.index')])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('students.destroy', $foreign), [], ['Referer' => route('students.index')])->assertNotFound();

        $this->assertSame('STU-FRG', $foreign->fresh()->student_number);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_each_permission_is_required_separately(): void
    {
        $college = $this->makeCollege('STEP');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)->get(route('students.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('students.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('students.edit', $student))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('students.store'), ['first_name' => 'Nope', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('students.update', $student), ['first_name' => 'Nope', 'status' => 'active'])->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('students.destroy', $student))->assertForbidden();
    }
}
