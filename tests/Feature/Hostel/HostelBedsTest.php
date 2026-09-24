<?php

namespace Tests\Feature\Hostel;

use App\Models\AuditLog;
use App\Models\HostelBed;
use Tests\TestCase;

/**
 * Hostel Management — Beds.
 *
 * The leaf of the hierarchy. Beyond the master-data invariants, the tests
 * focus on: the parent room must belong to a live room of the ACTIVE college,
 * denormalized hostel/building are server-derived, the parent is immutable,
 * a room's capacity is never exceeded, an occupied bed cannot be deleted, and
 * nothing here allocates a student (Phase 2 concern).
 */
class HostelBedsTest extends TestCase
{
    use HostelTestHelpers;

    public function test_a_bed_can_be_created_under_a_room_and_its_hierarchy_is_server_derived(): void
    {
        $college = $this->makeCollege('HBD01');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 2]);

        // Forged hostel / building / college ids must be ignored.
        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($room, [
                'hostel_id' => 9999,
                'building_id' => 9999,
                'college_id' => 9999,
                'created_by' => 9999,
            ]))
            ->assertRedirect(route('hostel-beds.index'));

        $bed = $this->withTenant($college, fn () => HostelBed::query()->where('bed_number', '1')->firstOrFail());

        $this->assertSame($college->id, $bed->college_id);
        $this->assertSame($room->id, $bed->room_id);
        $this->assertSame($room->hostel_id, $bed->hostel_id, 'hostel_id must be derived from the room.');
        $this->assertSame($room->building_id, $bed->building_id, 'building_id must be derived from the room.');
        $this->assertSame($user->id, $bed->created_by);

        $this->asCollege($college, $user)
            ->get(route('hostel-beds.index'))
            ->assertOk()
            ->assertSee('1')
            ->assertSee($room->room_number);
    }

    public function test_the_parent_room_must_belong_to_the_active_college(): void
    {
        $college = $this->makeCollege('HBD02');
        $other = $this->makeCollege('HBD02X');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create']);
        $foreignRoom = $this->makeHostelRoom($other);

        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($foreignRoom))
            ->assertSessionHasErrors('room_id');

        $this->assertSame(0, $this->withTenant($college, fn () => HostelBed::query()->count()));
    }

    public function test_a_soft_deleted_room_cannot_parent_a_new_bed(): void
    {
        $college = $this->makeCollege('HBD03');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create']);
        $room = $this->makeHostelRoom($college);
        $room->delete();

        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($room))
            ->assertSessionHasErrors('room_id');
    }

    public function test_duplicate_bed_numbers_are_rejected_within_a_room_but_allowed_elsewhere(): void
    {
        $college = $this->makeCollege('HBD04');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create']);
        $roomA = $this->makeHostelRoom($college, null, ['capacity' => 5]);
        $roomB = $this->makeHostelRoom($college, null, ['capacity' => 5]);
        $this->makeHostelBed($college, $roomA, ['bed_number' => '1']);

        // Same bed number, same room → duplicate.
        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($roomA, ['bed_number' => ' 1 ']))
            ->assertSessionHasErrors('bed_number');

        // Same bed number, a different room → allowed.
        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($roomB, ['bed_number' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelBed::query()->where('bed_number', '1')->count()));
    }

    public function test_a_bed_number_stays_reserved_after_the_bed_is_archived(): void
    {
        $college = $this->makeCollege('HBD05');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create', 'hostel_beds.delete']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 3]);
        $bed = $this->makeHostelBed($college, $room, ['bed_number' => 'RSVD']);

        $this->asCollege($college, $user)->delete(route('hostel-beds.destroy', $bed))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($room, ['bed_number' => 'RSVD']))
            ->assertSessionHasErrors('bed_number');

        $this->assertSame(1, $this->withTenant($college, fn () => HostelBed::withTrashed()->where('bed_number', 'RSVD')->count()));
    }

    public function test_status_must_be_one_of_the_defined_values(): void
    {
        $college = $this->makeCollege('HBD06');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 9]);

        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($room, ['status' => 'broken']))
            ->assertSessionHasErrors('status');

        foreach (['available', 'occupied', 'inactive'] as $i => $status) {
            $this->asCollege($college, $user)
                ->post(route('hostel-beds.store'), $this->bedPayload($room, ['bed_number' => (string) ($i + 1), 'status' => $status]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $this->withTenant($college, fn () => HostelBed::query()->count()));
    }

    public function test_a_rooms_capacity_is_never_exceeded(): void
    {
        $college = $this->makeCollege('HBD07');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 2]);

        $this->asCollege($college, $user)->post(route('hostel-beds.store'), $this->bedPayload($room, ['bed_number' => '1']))->assertSessionHasNoErrors();
        $this->asCollege($college, $user)->post(route('hostel-beds.store'), $this->bedPayload($room, ['bed_number' => '2']))->assertSessionHasNoErrors();

        // The room is full: a third bed is refused.
        $this->asCollege($college, $user)
            ->post(route('hostel-beds.store'), $this->bedPayload($room, ['bed_number' => '3']))
            ->assertSessionHasErrors('room_id');

        $this->assertSame(2, $this->withTenant($college, fn () => HostelBed::query()->where('room_id', $room->id)->count()));
    }

    public function test_a_bed_can_be_updated_and_its_parent_is_immutable(): void
    {
        $college = $this->makeCollege('HBD08');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.update']);
        $roomA = $this->makeHostelRoom($college, null, ['capacity' => 4]);
        $roomB = $this->makeHostelRoom($college, null, ['capacity' => 4]);
        $bed = $this->makeHostelBed($college, $roomA, ['bed_number' => '1']);

        $this->asCollege($college, $user)
            ->put(route('hostel-beds.update', $bed), $this->bedPayload($roomA, [
                'bed_number' => '1-Z',
                'status' => HostelBed::STATUS_OCCUPIED,
            ]))
            ->assertRedirect(route('hostel-beds.index'));

        $bed->refresh();
        $this->assertSame('1-Z', $bed->bed_number);
        $this->assertSame(HostelBed::STATUS_OCCUPIED, $bed->status);
        $this->assertSame($roomA->id, $bed->room_id);
        $this->assertSame($user->id, $bed->updated_by);

        // Reparenting to another room is never allowed.
        $this->asCollege($college, $user)
            ->put(route('hostel-beds.update', $bed), $this->bedPayload($roomB, ['bed_number' => '1-Z']))
            ->assertSessionHasNoErrors();
        $this->assertSame($roomA->id, $bed->refresh()->room_id);
    }

    public function test_an_occupied_bed_cannot_be_deleted_but_an_available_one_can(): void
    {
        $college = $this->makeCollege('HBD09');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.update', 'hostel_beds.delete']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 4]);
        $occupied = $this->makeHostelBed($college, $room, ['bed_number' => '1', 'status' => HostelBed::STATUS_OCCUPIED]);
        $available = $this->makeHostelBed($college, $room, ['bed_number' => '2']);

        $this->asCollege($college, $user)
            ->from(route('hostel-beds.index'))
            ->delete(route('hostel-beds.destroy', $occupied))
            ->assertRedirect(route('hostel-beds.index'))
            ->assertSessionHasErrors('bed');

        $this->assertDatabaseHas('hostel_beds', ['id' => $occupied->id, 'deleted_at' => null]);

        $this->asCollege($college, $user)
            ->delete(route('hostel-beds.destroy', $available))
            ->assertRedirect(route('hostel-beds.index'));

        $this->assertSoftDeleted('hostel_beds', ['id' => $available->id]);
    }

    public function test_beds_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('HBD10');
        $other = $this->makeCollege('HBD10X');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.update', 'hostel_beds.delete']);
        $room = $this->makeHostelRoom($college);
        $mine = $this->makeHostelBed($college, $room, ['bed_number' => 'MINE-1']);
        $theirs = $this->makeHostelBed($other, null, ['bed_number' => 'THEIRS-1']);

        $this->asCollege($college, $user)
            ->get(route('hostel-beds.index'))
            ->assertOk()
            ->assertSee('MINE-1')
            ->assertDontSee('THEIRS-1');

        $this->asCollege($college, $user)->get(route('hostel-beds.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('hostel-beds.update', $theirs), $this->bedPayload($room))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('hostel-beds.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('hostel_beds', ['id' => $theirs->id, 'bed_number' => 'THEIRS-1', 'deleted_at' => null]);
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('HBD11');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_beds.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 3]);
        $bed = $this->makeHostelBed($college, $room);

        $this->asCollege($college, $nobody)->get(route('hostel-beds.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('hostel-beds.create'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('hostel-beds.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-beds.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostel-beds.store'), $this->bedPayload($room))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-beds.edit', $bed))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('hostel-beds.update', $bed), $this->bedPayload($room))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('hostel-beds.destroy', $bed))->assertForbidden();

        $this->assertSame(1, $this->withTenant($college, fn () => HostelBed::query()->count()));
    }

    public function test_bed_changes_are_audited(): void
    {
        $college = $this->makeCollege('HBD12');
        $user = $this->makeUserWithPermissions($college, ['hostel_beds.view', 'hostel_beds.create', 'hostel_beds.update', 'hostel_beds.delete']);
        $room = $this->makeHostelRoom($college, null, ['capacity' => 3]);

        $this->asCollege($college, $user)->post(route('hostel-beds.store'), $this->bedPayload($room, ['bed_number' => 'AUD']))->assertRedirect();
        $bed = $this->withTenant($college, fn () => HostelBed::query()->where('bed_number', 'AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('hostel-beds.update', $bed), $this->bedPayload($room, ['bed_number' => 'AUD', 'status' => HostelBed::STATUS_INACTIVE]))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('hostel-beds.destroy', $bed))->assertRedirect();

        $actions = AuditLog::query()
            ->where('subject_type', HostelBed::class)
            ->where('subject_id', $bed->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['hostel_beds.created', 'hostel_beds.updated', 'hostel_beds.deleted'], $actions);

        $updated = AuditLog::query()->where('action', 'hostel_beds.updated')->where('subject_id', $bed->id)->firstOrFail();
        $this->assertSame('available', $updated->old_values['status']);
        $this->assertSame('inactive', $updated->new_values['status']);
    }
}
