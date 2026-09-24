<?php

namespace Tests\Feature\Hostel;

use App\Models\AuditLog;
use App\Models\HostelRoom;
use Tests\TestCase;

/**
 * Hostel Management — Rooms.
 *
 * Beyond the master-data invariants, the tests focus on the hierarchy rule:
 * a room must belong to a live building of the ACTIVE college, the denormalized
 * hostel is server-derived, the parent is immutable, capacity is enforced
 * against live beds, a room floor must fit the building, and a room with beds
 * cannot be deleted.
 */
class HostelRoomsTest extends TestCase
{
    use HostelTestHelpers;

    public function test_a_room_can_be_created_under_a_building_and_its_hostel_is_server_derived(): void
    {
        $college = $this->makeCollege('HRM01');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create']);
        $hostel = $this->makeHostel($college, ['name' => 'Boys Hostel']);
        $building = $this->makeHostelBuilding($college, $hostel, ['name' => 'North Block']);

        // A forged hostel_id must be ignored: it always comes from the building.
        $otherHostel = $this->makeHostel($college, ['name' => 'Decoy Hostel']);

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($building, [
                'hostel_id' => $otherHostel->id,
                'college_id' => 9999,
            ]))
            ->assertRedirect(route('hostel-rooms.index'));

        $room = $this->withTenant($college, fn () => HostelRoom::query()->where('room_number', '101')->firstOrFail());

        $this->assertSame($college->id, $room->college_id);
        $this->assertSame($building->id, $room->building_id);
        $this->assertSame($hostel->id, $room->hostel_id, 'hostel_id must be derived from the building.');
        $this->assertSame($user->id, $room->created_by);

        $this->asCollege($college, $user)
            ->get(route('hostel-rooms.index'))
            ->assertOk()
            ->assertSee('101')
            ->assertSee('North Block');
    }

    public function test_the_parent_building_must_belong_to_the_active_college(): void
    {
        $college = $this->makeCollege('HRM02');
        $other = $this->makeCollege('HRM02X');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create']);
        $foreignBuilding = $this->makeHostelBuilding($other);

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($foreignBuilding))
            ->assertSessionHasErrors('building_id');

        $this->assertSame(0, $this->withTenant($college, fn () => HostelRoom::query()->count()));
    }

    public function test_a_soft_deleted_building_cannot_parent_a_new_room(): void
    {
        $college = $this->makeCollege('HRM03');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create']);
        $building = $this->makeHostelBuilding($college);
        $building->delete();

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($building))
            ->assertSessionHasErrors('building_id');
    }

    public function test_duplicate_room_numbers_are_rejected_within_a_building_but_allowed_elsewhere(): void
    {
        $college = $this->makeCollege('HRM04');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create']);
        $buildingA = $this->makeHostelBuilding($college);
        $buildingB = $this->makeHostelBuilding($college);
        $this->makeHostelRoom($college, $buildingA, ['room_number' => '101']);

        // Same room number, same building → duplicate.
        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($buildingA, ['room_number' => ' 101 ']))
            ->assertSessionHasErrors('room_number');

        // Same room number, a different building → allowed.
        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($buildingB, ['room_number' => '101']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelRoom::query()->where('room_number', '101')->count()));
    }

    public function test_a_room_number_stays_reserved_after_the_room_is_archived(): void
    {
        $college = $this->makeCollege('HRM05');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create', 'hostel_rooms.delete']);
        $building = $this->makeHostelBuilding($college);
        $room = $this->makeHostelRoom($college, $building, ['room_number' => 'RSVD']);

        $this->asCollege($college, $user)->delete(route('hostel-rooms.destroy', $room))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($building, ['room_number' => 'RSVD']))
            ->assertSessionHasErrors('room_number');

        $this->assertSame(1, $this->withTenant($college, fn () => HostelRoom::withTrashed()->where('room_number', 'RSVD')->count()));
    }

    public function test_capacity_must_be_a_positive_integer(): void
    {
        $college = $this->makeCollege('HRM06');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create']);
        $building = $this->makeHostelBuilding($college);

        foreach ([0, -2, 'not-a-number', 1.5] as $bad) {
            $this->asCollege($college, $user)
                ->post(route('hostel-rooms.store'), $this->roomPayload($building, ['capacity' => $bad]))
                ->assertSessionHasErrors('capacity');
        }

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($building, ['capacity' => 3]))
            ->assertSessionHasNoErrors();
    }

    public function test_the_floor_must_fit_the_buildings_declared_floors(): void
    {
        $college = $this->makeCollege('HRM07');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create']);
        $building = $this->makeHostelBuilding($college, null, ['floors' => 3]);

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($building, ['floor' => 4]))
            ->assertSessionHasErrors('floor');

        $this->asCollege($college, $user)
            ->post(route('hostel-rooms.store'), $this->roomPayload($building, ['floor' => 3]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_room_can_be_updated_and_its_parent_is_immutable(): void
    {
        $college = $this->makeCollege('HRM08');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.update']);
        $buildingA = $this->makeHostelBuilding($college);
        $buildingB = $this->makeHostelBuilding($college);
        $room = $this->makeHostelRoom($college, $buildingA, ['room_number' => 'EDIT', 'capacity' => 2]);

        $this->asCollege($college, $user)
            ->put(route('hostel-rooms.update', $room), $this->roomPayload($buildingA, [
                'room_number' => 'EDIT',
                'capacity' => 4,
                'status' => HostelRoom::STATUS_INACTIVE,
            ]))
            ->assertRedirect(route('hostel-rooms.index'));

        $room->refresh();
        $this->assertSame(4, $room->capacity);
        $this->assertSame(HostelRoom::STATUS_INACTIVE, $room->status);
        $this->assertSame($buildingA->id, $room->building_id);

        // Reparenting to another building is never allowed — and its same
        // update otherwise succeeds.
        $this->asCollege($college, $user)
            ->put(route('hostel-rooms.update', $room), $this->roomPayload($buildingB, ['room_number' => 'EDIT']))
            ->assertSessionHasNoErrors();
        $this->assertSame($buildingA->id, $room->refresh()->building_id);
    }

    public function test_capacity_cannot_drop_below_the_live_bed_count(): void
    {
        $college = $this->makeCollege('HRM09');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.update']);
        $building = $this->makeHostelBuilding($college);
        $room = $this->makeHostelRoom($college, $building, ['capacity' => 3]);
        $this->makeHostelBed($college, $room);
        $this->makeHostelBed($college, $room);

        $this->asCollege($college, $user)
            ->put(route('hostel-rooms.update', $room), $this->roomPayload($building, ['capacity' => 1]))
            ->assertSessionHasErrors('capacity');

        $this->asCollege($college, $user)
            ->put(route('hostel-rooms.update', $room), $this->roomPayload($building, ['capacity' => 2]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_room_with_beds_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('HRM10');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.delete']);
        $room = $this->makeHostelRoom($college);
        $this->makeHostelBed($college, $room);

        $this->asCollege($college, $user)
            ->from(route('hostel-rooms.index'))
            ->delete(route('hostel-rooms.destroy', $room))
            ->assertRedirect(route('hostel-rooms.index'))
            ->assertSessionHasErrors('room');

        $this->assertDatabaseHas('hostel_rooms', ['id' => $room->id, 'deleted_at' => null]);
    }

    public function test_a_room_can_be_deleted_when_it_has_no_beds(): void
    {
        $college = $this->makeCollege('HRM11');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.delete']);
        $room = $this->makeHostelRoom($college);

        $this->asCollege($college, $user)
            ->delete(route('hostel-rooms.destroy', $room))
            ->assertRedirect(route('hostel-rooms.index'));

        $this->assertSoftDeleted('hostel_rooms', ['id' => $room->id]);
    }

    public function test_rooms_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('HRM12');
        $other = $this->makeCollege('HRM12X');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.update', 'hostel_rooms.delete']);
        $building = $this->makeHostelBuilding($college);
        $mine = $this->makeHostelRoom($college, $building, ['room_number' => 'MINE-1']);
        $theirs = $this->makeHostelRoom($other, null, ['room_number' => 'THEIRS-1']);

        $this->asCollege($college, $user)
            ->get(route('hostel-rooms.index'))
            ->assertOk()
            ->assertSee('MINE-1')
            ->assertDontSee('THEIRS-1');

        $this->asCollege($college, $user)->get(route('hostel-rooms.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('hostel-rooms.update', $theirs), $this->roomPayload($building))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('hostel-rooms.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('hostel_rooms', ['id' => $theirs->id, 'room_number' => 'THEIRS-1', 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('HRM13');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_rooms.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $building = $this->makeHostelBuilding($college);
        $room = $this->makeHostelRoom($college, $building);

        $this->asCollege($college, $nobody)->get(route('hostel-rooms.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('hostel-rooms.create'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('hostel-rooms.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-rooms.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostel-rooms.store'), $this->roomPayload($building))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-rooms.edit', $room))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('hostel-rooms.update', $room), $this->roomPayload($building))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('hostel-rooms.destroy', $room))->assertForbidden();

        $this->assertSame(1, $this->withTenant($college, fn () => HostelRoom::query()->count()));
    }

    public function test_room_changes_are_audited(): void
    {
        $college = $this->makeCollege('HRM14');
        $user = $this->makeUserWithPermissions($college, ['hostel_rooms.view', 'hostel_rooms.create', 'hostel_rooms.update', 'hostel_rooms.delete']);
        $building = $this->makeHostelBuilding($college);

        $this->asCollege($college, $user)->post(route('hostel-rooms.store'), $this->roomPayload($building, ['room_number' => 'AUD']))->assertRedirect();
        $room = $this->withTenant($college, fn () => HostelRoom::query()->where('room_number', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('hostel-rooms.update', $room), $this->roomPayload($building, ['room_number' => 'AUD', 'capacity' => 5]))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('hostel-rooms.destroy', $room))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', HostelRoom::class)
            ->where('subject_id', $room->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['hostel_rooms.created', 'hostel_rooms.updated', 'hostel_rooms.deleted'], $actions);

        $updated = AuditLog::query()->where('action', 'hostel_rooms.updated')->where('subject_id', $room->id)->firstOrFail();
        $this->assertSame(2, (int) $updated->old_values['capacity']);
        $this->assertSame(5, (int) $updated->new_values['capacity']);
    }
}
