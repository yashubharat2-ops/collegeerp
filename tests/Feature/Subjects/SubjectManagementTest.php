<?php

namespace Tests\Feature\Subjects;

use App\Models\{AuditLog, Department, Subject};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SubjectManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_subject_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('SUBM');
        $admin = $this->makeUserWithPermissions($college, ['subjects.view', 'subjects.create']);
        $dept = Department::create(['college_id' => $college->id, 'name' => 'Math Department', 'code' => 'MATH', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('subjects.store'), [
                'department_id' => $dept->id,
                'name' => 'Calculus I',
                'code' => 'MATH101',
                'short_name' => 'Calc 1',
                'subject_type' => 'theory',
                'credits' => 4,
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'active',
                'description' => 'Introduction to differential and integral calculus',
            ], ['Referer' => route('subjects.index')])
            ->assertRedirect(route('subjects.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subjects', [
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'name' => 'Calculus I',
            'code' => 'MATH101',
            'short_name' => 'Calc 1',
            'subject_type' => 'theory',
            'credits' => 4,
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => 'active',
        ]);
    }

    public function test_validation_rejects_invalid_marks_and_missing_fields(): void
    {
        $college = $this->makeCollege('SUBV');
        $admin = $this->makeUserWithPermissions($college, ['subjects.view', 'subjects.create']);

        // Missing name and code
        $this->asCollege($college, $admin)
            ->post(route('subjects.store'), [
                'name' => '',
                'code' => '',
                'status' => 'archived',
            ], ['Referer' => route('subjects.index')])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        // Passing marks greater than max marks
        $this->asCollege($college, $admin)
            ->post(route('subjects.store'), [
                'name' => 'Invalid Marks Course',
                'code' => 'INV101',
                'max_marks' => 50,
                'passing_marks' => 60,
                'status' => 'active',
            ], ['Referer' => route('subjects.index')])
            ->assertSessionHasErrors('passing_marks');

        $this->assertSame(0, Subject::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_code_in_same_college_is_blocked(): void
    {
        $college = $this->makeCollege('SUBD');
        $admin = $this->makeUserWithPermissions($college, ['subjects.view', 'subjects.create', 'subjects.update']);

        Subject::create([
            'college_id' => $college->id,
            'name' => 'Physics I',
            'code' => 'PHY101',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('subjects.store'), [
                'name' => 'Physics I Duplicate',
                'code' => 'PHY101',
                'status' => 'active',
            ], ['Referer' => route('subjects.index')])
            ->assertSessionHasErrors('code');

        $subject = Subject::withoutGlobalScopes()->where('college_id', $college->id)->where('code', 'PHY101')->firstOrFail();
        $this->asCollege($college, $admin)
            ->put(route('subjects.update', $subject), [
                'name' => 'Physics I Updated',
                'code' => 'PHY101',
                'status' => 'inactive',
            ], ['Referer' => route('subjects.edit', $subject)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('inactive', $subject->fresh()->status);
    }

    public function test_admin_can_update_and_soft_delete_subject(): void
    {
        $college = $this->makeCollege('SUBU');
        $admin = $this->makeUserWithPermissions($college, ['subjects.view', 'subjects.update', 'subjects.delete']);

        $subject = Subject::create([
            'college_id' => $college->id,
            'name' => 'Organic Chemistry',
            'code' => 'CHEM201',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('subjects.update', $subject), [
                'name' => 'Advanced Organic Chemistry',
                'code' => 'CHEM201',
                'short_name' => 'Adv Org Chem',
                'subject_type' => 'practical',
                'credits' => 3.5,
                'max_marks' => 100,
                'passing_marks' => 35,
                'status' => 'active',
                'description' => 'Updated syllabus',
            ], ['Referer' => route('subjects.edit', $subject)])
            ->assertSessionHas('success');

        $subject->refresh();
        $this->assertSame('Advanced Organic Chemistry', $subject->name);
        $this->assertSame('practical', $subject->subject_type);
        $this->assertSame('3.50', (string) $subject->credits);

        $this->asCollege($college, $admin)
            ->delete(route('subjects.destroy', $subject), [], ['Referer' => route('subjects.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('subjects', ['id' => $subject->id]);
        $this->asCollege($college, $admin)->get(route('subjects.index'))->assertDontSee('Advanced Organic Chemistry');
    }

    public function test_audit_logs_record_subject_actions(): void
    {
        $college = $this->makeCollege('SUBA');
        $admin = $this->makeUserWithPermissions($college, ['subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete']);

        $this->asCollege($college, $admin)->post(route('subjects.store'), [
            'name' => 'Audit Subject',
            'code' => 'AUD-SUB',
            'status' => 'active',
        ], ['Referer' => route('subjects.index')])->assertSessionHasNoErrors();

        $sub = Subject::withoutGlobalScopes()->where('code', 'AUD-SUB')->firstOrFail();

        $this->asCollege($college, $admin)->put(route('subjects.update', $sub), [
            'name' => 'Audit Subject Renamed',
            'code' => 'AUD-SUB',
            'status' => 'active',
        ], ['Referer' => route('subjects.edit', $sub)])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)->delete(route('subjects.destroy', $sub), [], ['Referer' => route('subjects.index')]);

        $base = [
            'college_id' => $college->id,
            'user_id' => $admin->id,
            'subject_type' => Subject::class,
            'subject_id' => $sub->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'subject.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'subject.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'subject.deleted']);
    }
}
