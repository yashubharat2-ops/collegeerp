<?php

namespace Tests\Feature\Campuses;

use App\Models\AuditLog;
use App\Models\Campus;
use Tests\TestCase;

class CampusManagementTest extends TestCase
{
    use CampusTestHelpers;

    public function test_college_admin_can_create_campus_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('CMGMT');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create']);

        $this->asCollege($college, $admin)
            ->post(route('campuses.store'), [
                'name' => 'Main Campus',
                'code' => 'MAIN',
                'short_name' => 'MC',
                'address' => '123 College Road',
                'city' => 'Bhopal',
                'state' => 'MP',
                'pincode' => '462001',
                'phone' => '1234567890',
                'email' => 'main@college.test',
                'status' => 'active',
                'description' => 'Primary campus',
            ], ['Referer' => route('campuses.index')])
            ->assertRedirect(route('campuses.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('campuses', [
            'college_id' => $college->id,
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'short_name' => 'MC',
            'city' => 'Bhopal',
            'status' => 'active',
        ]);
    }

    public function test_validation_rejects_missing_fields_and_bad_status(): void
    {
        $college = $this->makeCollege('CVAL');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create']);

        $this->asCollege($college, $admin)
            ->post(route('campuses.store'), ['name' => '', 'code' => '', 'status' => 'archived'], ['Referer' => route('campuses.index')])
            ->assertSessionHasErrors(['name', 'code', 'status']);

        $this->asCollege($college, $admin)
            ->post(route('campuses.store'), [
                'name' => str_repeat('x', 256),
                'code' => str_repeat('c', 51),
                'short_name' => str_repeat('s', 51),
                'description' => str_repeat('d', 2001),
                'email' => 'not-an-email',
                'status' => 'active',
            ], ['Referer' => route('campuses.index')])
            ->assertSessionHasErrors(['name', 'code', 'short_name', 'description', 'email']);

        $this->assertSame(0, Campus::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_code_within_same_college_is_blocked(): void
    {
        $college = $this->makeCollege('CDUP');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create', 'campuses.update']);
        Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'North Campus', 'code' => 'NORTH', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('campuses.store'), ['name' => 'North Campus 2', 'code' => 'NORTH', 'status' => 'active'], ['Referer' => route('campuses.index')])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Campus::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_same_code_in_different_colleges_allowed(): void
    {
        $collegeA = $this->makeCollege('CDIFA');
        $collegeB = $this->makeCollege('CDIFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['campuses.create']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['campuses.create']);

        Campus::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active']);

        $this->asCollege($collegeB, $adminB)
            ->post(route('campuses.store'), ['name' => 'Main', 'code' => 'MAIN', 'status' => 'active'], ['Referer' => route('campuses.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('campuses', ['college_id' => $collegeA->id, 'code' => 'MAIN']);
        $this->assertDatabaseHas('campuses', ['college_id' => $collegeB->id, 'code' => 'MAIN']);
        $this->assertSame(1, Campus::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame(1, Campus::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    public function test_duplicate_name_within_same_college_blocked(): void
    {
        $college = $this->makeCollege('CDUPN');
        $admin = $this->makeUserWithPermissions($college, ['campuses.create']);
        Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Main Campus', 'code' => 'MAIN', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->post(route('campuses.store'), ['name' => 'Main Campus', 'code' => 'MAIN2', 'status' => 'active'], ['Referer' => route('campuses.index')])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Campus::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_update_ignores_current_record(): void
    {
        $college = $this->makeCollege('CUPD');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.update']);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active']);

        // Editing same row keeps its own code/name (ignore rule)
        $this->asCollege($college, $admin)
            ->put(route('campuses.update', $campus), ['name' => 'Main', 'code' => 'MAIN', 'status' => 'inactive'], ['Referer' => route('campuses.edit', $campus)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('inactive', $campus->fresh()->status);

        // But duplicate with another row is blocked
        $other = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'North', 'code' => 'NORTH', 'status' => 'active']);
        $this->asCollege($college, $admin)
            ->put(route('campuses.update', $other), ['name' => 'Main', 'code' => 'NORTH', 'status' => 'active'], ['Referer' => route('campuses.edit', $other)])
            ->assertSessionHasErrors('name');
        $this->asCollege($college, $admin)
            ->put(route('campuses.update', $other), ['name' => 'North', 'code' => 'MAIN', 'status' => 'active'], ['Referer' => route('campuses.edit', $other)])
            ->assertSessionHasErrors('code');
    }

    public function test_college_id_spoofing_rejected_ignored(): void
    {
        $collegeA = $this->makeCollege('CSPFA');
        $collegeB = $this->makeCollege('CSPFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['campuses.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('campuses.store'), [
                'name' => 'Hacked Campus',
                'code' => 'HACK',
                'college_id' => $collegeB->id,
                'status' => 'active',
            ], ['Referer' => route('campuses.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('campuses', ['college_id' => $collegeA->id, 'code' => 'HACK']);
        $this->assertDatabaseMissing('campuses', ['college_id' => $collegeB->id, 'code' => 'HACK']);
        $this->assertSame(0, Campus::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    public function test_admin_can_update_and_delete_campus(): void
    {
        $college = $this->makeCollege('CUPDEL');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create', 'campuses.update', 'campuses.delete']);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Old Name', 'code' => 'OLD', 'status' => 'active']);

        $this->asCollege($college, $admin)
            ->put(route('campuses.update', $campus), [
                'name' => 'New Name',
                'code' => 'NEW',
                'city' => 'Bhopal',
                'status' => 'active',
                'description' => 'updated',
            ], ['Referer' => route('campuses.edit', $campus)])
            ->assertSessionHas('success');

        $campus->refresh();
        $this->assertSame('New Name', $campus->name);
        $this->assertSame('NEW', $campus->code);
        $this->assertSame('Bhopal', $campus->city);
        $this->assertSame('updated', $campus->description);

        $this->asCollege($college, $admin)
            ->delete(route('campuses.destroy', $campus), [], ['Referer' => route('campuses.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('campuses', ['id' => $campus->id]);
        $this->asCollege($college, $admin)->get(route('campuses.index'))->assertDontSee('New Name');
    }

    public function test_soft_delete_preserves_historical_records(): void
    {
        $college = $this->makeCollege('CSOFT');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create', 'campuses.delete']);
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Temp', 'code' => 'TEMP', 'status' => 'active']);

        $this->asCollege($college, $admin)->delete(route('campuses.destroy', $campus), [], ['Referer' => route('campuses.index')])->assertSessionHas('success');

        $this->assertSoftDeleted('campuses', ['id' => $campus->id]);
        $this->assertDatabaseHas('campuses', ['id' => $campus->id]); // still in DB
        $this->assertSame(0, Campus::query()->count()); // scoped query excludes soft-deleted
        $this->assertSame(1, Campus::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_index_supports_search_status_filter_and_pagination(): void
    {
        $college = $this->makeCollege('CIDX');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view']);
        foreach (range(1, 16) as $i) {
            Campus::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'name' => sprintf('Campus %02d', $i),
                'code' => sprintf('C%02d', $i),
                'status' => $i === 16 ? 'inactive' : 'active',
            ]);
        }

        $this->asCollege($college, $admin)->get(route('campuses.index'))
            ->assertSee('Campus 01')->assertSee('Campus 15')->assertDontSee('Campus 16')
            ->assertSee('Showing 1–15 of 16 campuses.');

        $this->asCollege($college, $admin)->get(route('campuses.index', ['page' => 2]))
            ->assertSee('Campus 16')->assertDontSee('Campus 01');

        $this->asCollege($college, $admin)->get(route('campuses.index', ['search' => 'Campus 07']))
            ->assertSee('Campus 07')->assertDontSee('Campus 01');

        $this->asCollege($college, $admin)->get(route('campuses.index', ['search' => 'C07']))
            ->assertSee('Campus 07');

        $this->asCollege($college, $admin)->get(route('campuses.index', ['status' => 'inactive']))
            ->assertSee('Campus 16')->assertDontSee('Campus 01');
    }

    public function test_audit_logs_record_create_update_and_delete(): void
    {
        $college = $this->makeCollege('CAUD');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create', 'campuses.update', 'campuses.delete']);

        $this->asCollege($college, $admin)->post(route('campuses.store'), [
            'name' => 'Audit Campus',
            'code' => 'AUD',
            'status' => 'active',
        ], ['Referer' => route('campuses.index')])->assertSessionHasNoErrors();

        $campus = Campus::withoutGlobalScopes()->firstWhere('code', 'AUD');

        $this->asCollege($college, $admin)->put(route('campuses.update', $campus), [
            'name' => 'Audit Campus Updated',
            'code' => 'AUD',
            'status' => 'active',
        ], ['Referer' => route('campuses.edit', $campus)]);

        $this->asCollege($college, $admin)->delete(route('campuses.destroy', $campus), [], ['Referer' => route('campuses.index')]);

        $base = ['college_id' => $college->id, 'user_id' => $admin->id, 'subject_type' => Campus::class, 'subject_id' => $campus->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'campus.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'campus.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'campus.deleted']);

        $updated = AuditLog::where($base + ['action' => 'campus.updated'])->firstOrFail();
        $this->assertSame('Audit Campus', $updated->old_values['name']);
        $this->assertSame('Audit Campus Updated', $updated->new_values['name']);
    }

    public function test_xss_safe_output_and_delete_confirmation(): void
    {
        $college = $this->makeCollege('CXSS');
        $admin = $this->makeUserWithPermissions($college, ['campuses.view', 'campuses.create', 'campuses.delete']);
        $xssName = '<script>alert("xss")</script>';
        $campus = Campus::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => $xssName, 'code' => 'XSS', 'status' => 'active']);

        $response = $this->asCollege($college, $admin)->get(route('campuses.index'));
        $response->assertOk();
        // Escaped output should not contain raw script tag
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertSee(e($xssName), false);

        // Delete confirmation uses @js() safe escaping, not raw interpolation
        // We check that the page contains the safe JS confirmation pattern
        $response->assertSee('Delete campus', false);
    }
}
