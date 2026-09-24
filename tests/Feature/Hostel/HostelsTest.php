<?php

namespace Tests\Feature\Hostel;

use App\Models\AuditLog;
use App\Models\Hostel;
use Tests\TestCase;

/**
 * Hostel Management — Hostels (master data, validation, tenancy, RBAC).
 *
 * Hostels anchor the whole hierarchy, so the tests focus on the invariants
 * that matter: unique code per college (reserved on archived records), unique
 * active name, strict college scoping, permission gating, audit logging and
 * the dependency delete guard.
 */
class HostelsTest extends TestCase
{
    use HostelTestHelpers;

    public function test_a_hostel_can_be_created_and_listed(): void
    {
        $college = $this->makeCollege('HST01');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload())
            ->assertRedirect(route('hostels.index'));

        $hostel = $this->withTenant($college, fn () => Hostel::query()->where('code', 'BH-A')->firstOrFail());

        $this->assertSame($college->id, $hostel->college_id);
        $this->assertSame($user->id, $hostel->created_by);
        $this->assertSame($user->id, $hostel->updated_by);
        $this->assertSame(Hostel::STATUS_ACTIVE, $hostel->status);

        $this->asCollege($college, $user)
            ->get(route('hostels.index'))
            ->assertOk()
            ->assertSee('Boys Hostel')
            ->assertSee('BH-A');
    }

    public function test_the_college_id_and_audit_columns_cannot_be_forged(): void
    {
        $college = $this->makeCollege('HST02');
        $other = $this->makeCollege('HST02X');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload([
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
            ]))
            ->assertRedirect();

        $hostel = $this->withTenant($college, fn () => Hostel::query()->where('code', 'BH-A')->firstOrFail());

        $this->assertSame($college->id, $hostel->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $hostel->created_by);
        $this->assertSame($user->id, $hostel->updated_by);
    }

    public function test_codes_are_stored_upper_cased_and_compared_case_insensitively(): void
    {
        $college = $this->makeCollege('HST03');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['name' => 'Gents One', 'code' => ' gh-1 ']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => Hostel::query()->where('code', 'GH-1')->count()));

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['name' => 'Gents Two', 'code' => 'Gh-1']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_duplicate_code_is_rejected_within_a_college(): void
    {
        $college = $this->makeCollege('HST04');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create']);
        $this->makeHostel($college, ['code' => 'DUP']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['code' => 'DUP']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->withTenant($college, fn () => Hostel::query()->where('code', 'DUP')->count()));
    }

    public function test_a_code_stays_reserved_after_the_hostel_is_archived(): void
    {
        $college = $this->makeCollege('HST05');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create', 'hostels.delete']);
        $hostel = $this->makeHostel($college, ['code' => 'RSVD']);

        $this->asCollege($college, $user)->delete(route('hostels.destroy', $hostel))->assertRedirect();
        $this->assertSoftDeleted('hostels', ['id' => $hostel->id]);

        // Identifiers are never reused: historical references stay unambiguous.
        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['code' => 'RSVD']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->withTenant($college, fn () => Hostel::withTrashed()->where('code', 'RSVD')->count()));
    }

    public function test_the_same_code_may_be_used_by_two_colleges(): void
    {
        $college = $this->makeCollege('HST06');
        $other = $this->makeCollege('HST06X');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create']);
        $this->makeHostel($other, ['code' => 'SHARED']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['code' => 'SHARED']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => Hostel::query()->where('code', 'SHARED')->count()));
        $this->assertSame(1, $this->withTenant($other, fn () => Hostel::query()->where('code', 'SHARED')->count()));
    }

    public function test_duplicate_active_names_are_rejected_but_an_archived_name_is_free(): void
    {
        $college = $this->makeCollege('HST07');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create', 'hostels.delete']);
        $existing = $this->makeHostel($college, ['name' => 'Riverside Hostel']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['name' => 'Riverside Hostel']))
            ->assertSessionHasErrors('name');

        $this->asCollege($college, $user)->delete(route('hostels.destroy', $existing))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['name' => 'Riverside Hostel']))
            ->assertSessionHasNoErrors();
    }

    public function test_validation_rejects_incomplete_payloads(): void
    {
        $college = $this->makeCollege('HST08');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'hostel_type', 'gender', 'status']);

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['hostel_type' => 'resort']))
            ->assertSessionHasErrors('hostel_type');

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['gender' => 'other']))
            ->assertSessionHasErrors('gender');

        $this->asCollege($college, $user)
            ->post(route('hostels.store'), $this->hostelPayload(['status' => 'archived']))
            ->assertSessionHasErrors('status');
    }

    public function test_a_hostel_can_be_updated_and_deleted(): void
    {
        $college = $this->makeCollege('HST09');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.update', 'hostels.delete']);
        $hostel = $this->makeHostel($college, ['name' => 'Old Hostel', 'code' => 'EDIT']);

        $this->asCollege($college, $user)
            ->get(route('hostels.edit', $hostel))
            ->assertOk()
            ->assertSee('Old Hostel');

        $this->asCollege($college, $user)
            ->put(route('hostels.update', $hostel), $this->hostelPayload([
                'name' => 'New Hostel',
                'code' => 'EDIT',
                'status' => Hostel::STATUS_INACTIVE,
            ]))
            ->assertRedirect(route('hostels.index'));

        $hostel->refresh();
        $this->assertSame('New Hostel', $hostel->name);
        $this->assertSame(Hostel::STATUS_INACTIVE, $hostel->status);
        $this->assertSame($user->id, $hostel->updated_by);

        $this->asCollege($college, $user)
            ->delete(route('hostels.destroy', $hostel))
            ->assertRedirect(route('hostels.index'));

        $this->assertSoftDeleted('hostels', ['id' => $hostel->id]);
    }

    public function test_updating_keeps_the_code_unique_among_other_hostels(): void
    {
        $college = $this->makeCollege('HST10');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.update']);
        $this->makeHostel($college, ['code' => 'TAKEN']);
        $hostel = $this->makeHostel($college, ['code' => 'MINE']);

        // Its own code is fine…
        $this->asCollege($college, $user)
            ->put(route('hostels.update', $hostel), $this->hostelPayload(['code' => 'MINE']))
            ->assertSessionHasNoErrors();

        // …another hostel's code is not.
        $this->asCollege($college, $user)
            ->put(route('hostels.update', $hostel), $this->hostelPayload(['code' => 'TAKEN']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_hostel_with_dependents_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('HST11');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.delete']);
        $hostel = $this->makeHostel($college, ['code' => 'INUSE']);
        $this->makeHostelBuilding($college, $hostel);

        $this->asCollege($college, $user)
            ->from(route('hostels.index'))
            ->delete(route('hostels.destroy', $hostel))
            ->assertRedirect(route('hostels.index'))
            ->assertSessionHasErrors('hostel');

        $this->assertDatabaseHas('hostels', ['id' => $hostel->id, 'deleted_at' => null]);
    }

    public function test_hostels_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('HST12');
        $other = $this->makeCollege('HST12X');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.update', 'hostels.delete']);
        $mine = $this->makeHostel($college, ['name' => 'Mine Only']);
        $theirs = $this->makeHostel($other, ['name' => 'Theirs Only']);

        $this->asCollege($college, $user)
            ->get(route('hostels.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Theirs Only');

        $this->asCollege($college, $user)->get(route('hostels.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('hostels.update', $theirs), $this->hostelPayload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('hostels.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('hostels', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);
        $this->assertDatabaseHas('hostels', ['id' => $mine->id, 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('HST13');
        $viewer = $this->makeUserWithPermissions($college, ['hostels.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $hostel = $this->makeHostel($college);

        $this->asCollege($college, $nobody)->get(route('hostels.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('hostels.create'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('hostels.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostels.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostels.store'), $this->hostelPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostels.edit', $hostel))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('hostels.update', $hostel), $this->hostelPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('hostels.destroy', $hostel))->assertForbidden();

        $this->assertSame(1, $this->withTenant($college, fn () => Hostel::query()->count()));
    }

    public function test_a_super_admin_can_manage_hostels_in_the_active_college(): void
    {
        $college = $this->makeCollege('HST14');
        $super = $this->makeSuperAdmin($college);

        $this->asCollege($college, $super)
            ->post(route('hostels.store'), $this->hostelPayload(['code' => 'SUPER']))
            ->assertRedirect(route('hostels.index'));

        $hostel = $this->withTenant($college, fn () => Hostel::query()->where('code', 'SUPER')->firstOrFail());
        $this->assertSame($college->id, $hostel->college_id);
    }

    public function test_hostel_changes_are_audited(): void
    {
        $college = $this->makeCollege('HST15');
        $user = $this->makeUserWithPermissions($college, ['hostels.view', 'hostels.create', 'hostels.update', 'hostels.delete']);

        $this->asCollege($college, $user)->post(route('hostels.store'), $this->hostelPayload(['code' => 'AUD']))->assertRedirect();
        $hostel = $this->withTenant($college, fn () => Hostel::query()->where('code', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('hostels.update', $hostel), $this->hostelPayload(['code' => 'AUD', 'name' => 'Renamed Hostel']))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('hostels.destroy', $hostel))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', Hostel::class)
            ->where('subject_id', $hostel->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['hostels.created', 'hostels.updated', 'hostels.deleted'], $actions);

        $updated = AuditLog::query()->where('action', 'hostels.updated')->where('subject_id', $hostel->id)->firstOrFail();
        $this->assertSame('Boys Hostel', $updated->old_values['name']);
        $this->assertSame('Renamed Hostel', $updated->new_values['name']);
        $this->assertSame($college->id, $updated->college_id);
        $this->assertSame($user->id, $updated->user_id);
    }
}
