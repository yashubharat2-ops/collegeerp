<?php

namespace Tests\Feature\Hostel;

use App\Models\AuditLog;
use App\Models\HostelBuilding;
use Tests\TestCase;

/**
 * Hostel Management — Buildings / Blocks.
 *
 * Beyond the master-data invariants (unique code per hostel, reserved codes,
 * tenant isolation, RBAC, audit), the tests focus on the parent rule: a
 * building must belong to a live hostel of the ACTIVE college, its parent is
 * immutable, and a building with rooms or beds cannot be deleted.
 */
class HostelBuildingsTest extends TestCase
{
    use HostelTestHelpers;

    public function test_a_building_can_be_created_under_a_hostel_and_listed(): void
    {
        $college = $this->makeCollege('HBL01');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create']);
        $hostel = $this->makeHostel($college, ['name' => 'Boys Hostel']);

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel))
            ->assertRedirect(route('hostel-buildings.index'));

        $building = $this->withTenant($college, fn () => HostelBuilding::query()->where('code', 'NB')->firstOrFail());

        $this->assertSame($college->id, $building->college_id);
        $this->assertSame($hostel->id, $building->hostel_id);
        $this->assertSame($user->id, $building->created_by);

        $this->asCollege($college, $user)
            ->get(route('hostel-buildings.index'))
            ->assertOk()
            ->assertSee('North Block')
            ->assertSee('Boys Hostel');
    }

    public function test_the_college_id_cannot_be_forged_and_the_hostel_id_is_validated_contextually(): void
    {
        $college = $this->makeCollege('HBL02');
        $other = $this->makeCollege('HBL02X');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create']);

        // A foreign college's hostel is not a valid parent.
        $foreignHostel = $this->makeHostel($other, ['name' => 'Foreign Hostel']);

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($foreignHostel))
            ->assertSessionHasErrors('hostel_id');

        $this->assertSame(0, $this->withTenant($college, fn () => HostelBuilding::query()->count()));

        // A forged college_id never reaches the record.
        $hostel = $this->makeHostel($college);
        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel, ['college_id' => $other->id, 'created_by' => 9999]))
            ->assertSessionHasNoErrors();

        $building = $this->withTenant($college, fn () => HostelBuilding::query()->firstOrFail());
        $this->assertSame($college->id, $building->college_id);
        $this->assertSame($user->id, $building->created_by);
    }

    public function test_a_soft_deleted_hostel_cannot_parent_a_new_building(): void
    {
        $college = $this->makeCollege('HBL03');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create', 'hostels.delete']);
        $hostel = $this->makeHostel($college);
        $hostel->delete();

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel))
            ->assertSessionHasErrors('hostel_id');
    }

    public function test_duplicate_codes_are_rejected_within_a_hostel_but_allowed_elsewhere(): void
    {
        $college = $this->makeCollege('HBL04');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create']);
        $hostelA = $this->makeHostel($college, ['name' => 'Hostel A']);
        $hostelB = $this->makeHostel($college, ['name' => 'Hostel B']);
        $this->makeHostelBuilding($college, $hostelA, ['code' => 'NB']);

        // Same code, same hostel → duplicate.
        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostelA, ['code' => ' nb ']))
            ->assertSessionHasErrors('code');

        // Same code, a different hostel → allowed.
        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostelB, ['code' => 'NB']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelBuilding::query()->where('code', 'NB')->count()));
    }

    public function test_a_code_stays_reserved_after_the_building_is_archived(): void
    {
        $college = $this->makeCollege('HBL05');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create', 'hostel_buildings.delete']);
        $hostel = $this->makeHostel($college);
        $building = $this->makeHostelBuilding($college, $hostel, ['code' => 'RSVD']);

        $this->asCollege($college, $user)->delete(route('hostel-buildings.destroy', $building))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel, ['code' => 'RSVD']))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $this->withTenant($college, fn () => HostelBuilding::withTrashed()->where('code', 'RSVD')->count()));
    }

    public function test_validation_rejects_incomplete_payloads_and_bad_floors(): void
    {
        $college = $this->makeCollege('HBL06');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create']);
        $hostel = $this->makeHostel($college);

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), [])
            ->assertSessionHasErrors(['hostel_id', 'name', 'code', 'status']);

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel, ['floors' => 0]))
            ->assertSessionHasErrors('floors');

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel, ['floors' => 'abc']))
            ->assertSessionHasErrors('floors');

        $this->asCollege($college, $user)
            ->post(route('hostel-buildings.store'), $this->buildingPayload($hostel, ['status' => 'archived']))
            ->assertSessionHasErrors('status');
    }

    public function test_a_building_can_be_updated_and_its_parent_is_immutable(): void
    {
        $college = $this->makeCollege('HBL07');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.update', 'hostel_buildings.delete']);
        $hostelA = $this->makeHostel($college, ['name' => 'Hostel A']);
        $hostelB = $this->makeHostel($college, ['name' => 'Hostel B']);
        $building = $this->makeHostelBuilding($college, $hostelA, ['name' => 'Old Block', 'code' => 'EDIT']);

        $this->asCollege($college, $user)
            ->get(route('hostel-buildings.edit', $building))
            ->assertOk()
            ->assertSee('Old Block');

        $this->asCollege($college, $user)
            ->put(route('hostel-buildings.update', $building), $this->buildingPayload($hostelA, [
                'name' => 'Renamed Block',
                'code' => 'EDIT',
                'floors' => 4,
            ]))
            ->assertRedirect(route('hostel-buildings.index'));

        $building->refresh();
        $this->assertSame('Renamed Block', $building->name);
        $this->assertSame(4, $building->floors);
        $this->assertSame($hostelA->id, $building->hostel_id);
        $this->assertSame($user->id, $building->updated_by);

        // Reparenting to another hostel is never allowed.
        $this->asCollege($college, $user)
            ->put(route('hostel-buildings.update', $building), $this->buildingPayload($hostelB, ['code' => 'EDIT']))
            ->assertSessionHasNoErrors();
        $this->assertSame($hostelA->id, $building->refresh()->hostel_id);

        $this->asCollege($college, $user)
            ->delete(route('hostel-buildings.destroy', $building))
            ->assertRedirect(route('hostel-buildings.index'));

        $this->assertSoftDeleted('hostel_buildings', ['id' => $building->id]);
    }

    public function test_a_building_with_rooms_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('HBL08');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.delete']);
        $hostel = $this->makeHostel($college);
        $building = $this->makeHostelBuilding($college, $hostel, ['code' => 'INUSE']);
        $this->makeHostelRoom($college, $building);

        $this->asCollege($college, $user)
            ->from(route('hostel-buildings.index'))
            ->delete(route('hostel-buildings.destroy', $building))
            ->assertRedirect(route('hostel-buildings.index'))
            ->assertSessionHasErrors('building');

        $this->assertDatabaseHas('hostel_buildings', ['id' => $building->id, 'deleted_at' => null]);
    }

    public function test_a_building_with_beds_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('HBL08B');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.delete']);
        $building = $this->makeHostelBuilding($college);
        $room = $this->makeHostelRoom($college, $building);
        $this->makeHostelBed($college, $room);

        $this->asCollege($college, $user)
            ->from(route('hostel-buildings.index'))
            ->delete(route('hostel-buildings.destroy', $building))
            ->assertRedirect(route('hostel-buildings.index'))
            ->assertSessionHasErrors('building');

        $this->assertDatabaseHas('hostel_buildings', ['id' => $building->id, 'deleted_at' => null]);
    }

    public function test_buildings_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('HBL09');
        $other = $this->makeCollege('HBL09X');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.update', 'hostel_buildings.delete']);
        $hostel = $this->makeHostel($college);
        $mine = $this->makeHostelBuilding($college, $hostel, ['name' => 'Mine Only']);
        $theirs = $this->makeHostelBuilding($other, $this->makeHostel($other), ['name' => 'Theirs Only']);

        $this->asCollege($college, $user)
            ->get(route('hostel-buildings.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Theirs Only');

        $this->asCollege($college, $user)->get(route('hostel-buildings.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('hostel-buildings.update', $theirs), $this->buildingPayload($hostel))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('hostel-buildings.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('hostel_buildings', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('HBL10');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_buildings.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $hostel = $this->makeHostel($college);
        $building = $this->makeHostelBuilding($college, $hostel);

        $this->asCollege($college, $nobody)->get(route('hostel-buildings.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('hostel-buildings.create'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('hostel-buildings.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-buildings.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostel-buildings.store'), $this->buildingPayload($hostel))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-buildings.edit', $building))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('hostel-buildings.update', $building), $this->buildingPayload($hostel))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('hostel-buildings.destroy', $building))->assertForbidden();

        $this->assertSame(1, $this->withTenant($college, fn () => HostelBuilding::query()->count()));
    }

    public function test_building_changes_are_audited(): void
    {
        $college = $this->makeCollege('HBL11');
        $user = $this->makeUserWithPermissions($college, ['hostel_buildings.view', 'hostel_buildings.create', 'hostel_buildings.update', 'hostel_buildings.delete']);
        $hostel = $this->makeHostel($college);

        $this->asCollege($college, $user)->post(route('hostel-buildings.store'), $this->buildingPayload($hostel, ['code' => 'AUD']))->assertRedirect();
        $building = $this->withTenant($college, fn () => HostelBuilding::query()->where('code', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('hostel-buildings.update', $building), $this->buildingPayload($hostel, ['code' => 'AUD', 'name' => 'Renamed Block']))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('hostel-buildings.destroy', $building))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', HostelBuilding::class)
            ->where('subject_id', $building->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['hostel_buildings.created', 'hostel_buildings.updated', 'hostel_buildings.deleted'], $actions);

        $created = AuditLog::query()->where('action', 'hostel_buildings.created')->where('subject_id', $building->id)->firstOrFail();
        $this->assertSame($hostel->id, (int) $created->new_values['hostel_id']);
    }
}
