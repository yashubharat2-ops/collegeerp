<?php

namespace Tests\Feature\Programs;

use App\Models\{AuditLog, Department, Program};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ProgramManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_program_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('MGMT');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.create']);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Science', 'code' => 'SCI', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => 'B.Sc. Physics', 'code' => 'BSP', 'short_name' => 'BScP', 'department_id' => $department->id, 'description' => 'Three-year programme', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertRedirect(route('programs.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('programs', ['college_id' => $college->id, 'name' => 'B.Sc. Physics', 'code' => 'BSP', 'short_name' => 'BScP', 'department_id' => $department->id, 'status' => 'active']);
    }

    public function test_validation_rejects_missing_fields_bad_status_and_max_lengths(): void
    {
        $college = $this->makeCollege('VAL');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.create']);

        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => '', 'code' => '', 'status' => 'archived'], ['Referer' => route('programs.index')])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => str_repeat('n', 256), 'code' => str_repeat('c', 51), 'short_name' => str_repeat('s', 51), 'description' => str_repeat('d', 2001), 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasErrors(['name', 'code', 'short_name', 'description']);

        $this->assertSame(0, Program::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_name_or_code_within_same_college_is_blocked(): void
    {
        $college = $this->makeCollege('DUP');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.create']);
        Program::create(['college_id' => $college->id, 'name' => 'Physics', 'code' => 'PHY', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => 'Applied Physics', 'code' => 'PHY', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasErrors('code');

        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => 'Physics', 'code' => 'PHY2', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Program::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_update_ignores_the_current_record_for_name_and_code(): void
    {
        $college = $this->makeCollege('IGN');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.update']);
        $physics = Program::create(['college_id' => $college->id, 'name' => 'Physics', 'code' => 'PHY', 'status' => 'active']);
        Program::create(['college_id' => $college->id, 'name' => 'Chemistry', 'code' => 'CHM', 'status' => 'active']);

        // Editing the same row may keep its own name and code (ignore rule).
        $this->asCollege($college, $admin)
            ->put(route('programs.update', $physics), ['name' => 'Physics', 'code' => 'PHY', 'short_name' => 'PH', 'status' => 'inactive'], ['Referer' => route('programs.edit', $physics)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $physics->refresh();
        $this->assertSame('inactive', $physics->status);
        $this->assertSame('PH', $physics->short_name);

        // Renaming onto a sibling program in the same college is rejected.
        $this->asCollege($college, $admin)
            ->put(route('programs.update', $physics), ['name' => 'Chemistry', 'code' => 'PHY', 'status' => 'inactive'], ['Referer' => route('programs.edit', $physics)])
            ->assertSessionHasErrors('name');
    }

    public function test_admin_can_update_program_including_its_department(): void
    {
        $college = $this->makeCollege('UPD');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.update']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'Mathematics', 'code' => 'MTH', 'status' => 'active']);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Sciences', 'code' => 'SCI', 'status' => 'active']);

        $this->asCollege($college, $admin)->get(route('programs.edit', $program))->assertOk()->assertSee('Mathematics');
        $this->asCollege($college, $admin)
            ->put(route('programs.update', $program), ['name' => 'Applied Mathematics', 'code' => 'MTH', 'department_id' => $department->id, 'status' => 'active'], ['Referer' => route('programs.edit', $program)])
            ->assertSessionHas('success');
        $program->refresh();
        $this->assertSame('Applied Mathematics', $program->name);
        $this->assertSame($department->id, $program->department_id);
    }

    public function test_department_id_is_optional_and_unknown_departments_are_rejected(): void
    {
        $college = $this->makeCollege('DEPT');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.create']);

        // A college-level program (no department) is valid.
        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => 'Open Elective', 'code' => 'OEL', 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertNull(Program::withoutGlobalScopes()->firstWhere('code', 'OEL')->department_id);

        // A department id that does not exist at all is rejected.
        $this->asCollege($college, $admin)
            ->post(route('programs.store'), ['name' => 'Ghost Track', 'code' => 'GHST', 'department_id' => 99999, 'status' => 'active'], ['Referer' => route('programs.index')])
            ->assertSessionHasErrors('department_id');

        $this->assertSame(1, Program::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_soft_delete_hides_the_program_but_keeps_the_row(): void
    {
        $college = $this->makeCollege('DEL');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.delete']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'Zoology', 'code' => 'ZOO', 'status' => 'active']);

        $this->asCollege($college, $admin)->delete(route('programs.destroy', $program), [], ['Referer' => route('programs.index')])->assertSessionHas('success');

        $this->assertSoftDeleted('programs', ['id' => $program->id]);
        // The row is preserved (never permanently deleted) and disappears from the listing.
        $this->assertDatabaseHas('programs', ['id' => $program->id, 'name' => 'Zoology']);
        $this->asCollege($college, $admin)->get(route('programs.index'))->assertDontSee('Zoology');
    }

    public function test_index_supports_search_status_filter_and_server_side_pagination(): void
    {
        $college = $this->makeCollege('IDX');
        $admin = $this->makeUserWithPermissions($college, ['programs.view']);
        foreach (range(1, 16) as $i) {
            Program::create(['college_id' => $college->id, 'name' => sprintf('Prog %02d', $i), 'code' => sprintf('P%02d', $i), 'status' => $i === 16 ? 'inactive' : 'active']);
        }

        $this->asCollege($college, $admin)->get(route('programs.index'))
            ->assertSee('Prog 01')->assertSee('Prog 15')->assertDontSee('Prog 16')
            ->assertSee('Showing 1–15 of 16 programs.');

        $this->asCollege($college, $admin)->get(route('programs.index', ['page' => 2]))
            ->assertSee('Prog 16')->assertDontSee('Prog 01');

        $this->asCollege($college, $admin)->get(route('programs.index', ['search' => 'Prog 07']))
            ->assertSee('Prog 07')->assertDontSee('Prog 01');

        $this->asCollege($college, $admin)->get(route('programs.index', ['search' => 'P07']))
            ->assertSee('Prog 07');

        $this->asCollege($college, $admin)->get(route('programs.index', ['status' => 'inactive']))
            ->assertSee('Prog 16')->assertDontSee('Prog 01');
    }

    public function test_audit_logs_record_create_update_and_delete(): void
    {
        $college = $this->makeCollege('AUD');
        $admin = $this->makeUserWithPermissions($college, ['programs.view', 'programs.create', 'programs.update', 'programs.delete']);

        $this->asCollege($college, $admin)->post(route('programs.store'), ['name' => 'Botany', 'code' => 'BOT', 'short_name' => 'B', 'status' => 'active'], ['Referer' => route('programs.index')])->assertSessionHasNoErrors();
        $program = Program::withoutGlobalScopes()->firstWhere('code', 'BOT');

        $this->asCollege($college, $admin)->put(route('programs.update', $program), ['name' => 'Plant Science', 'code' => 'BOT', 'status' => 'active'], ['Referer' => route('programs.edit', $program)]);
        $this->asCollege($college, $admin)->delete(route('programs.destroy', $program), [], ['Referer' => route('programs.index')]);

        $base = ['college_id' => $college->id, 'user_id' => $admin->id, 'subject_type' => Program::class, 'subject_id' => $program->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'program.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'program.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'program.deleted']);

        $created = AuditLog::where($base + ['action' => 'program.created'])->firstOrFail();
        $this->assertSame([], $created->old_values);
        $this->assertSame('Botany', $created->new_values['name']);
        $this->assertSame('BOT', $created->new_values['code']);
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($admin->id, $created->user_id);

        $updated = AuditLog::where($base + ['action' => 'program.updated'])->firstOrFail();
        $this->assertSame('Botany', $updated->old_values['name']);
        $this->assertSame('Plant Science', $updated->new_values['name']);

        $deleted = AuditLog::where($base + ['action' => 'program.deleted'])->firstOrFail();
        $this->assertSame('Plant Science', $deleted->old_values['name']);
        $this->assertSame([], $deleted->new_values);
    }
}
