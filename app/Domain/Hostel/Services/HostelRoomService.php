<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelRoomService — CRUD + integrity rules for hostel rooms
 * (Hostel Management, Phase 1).
 *
 * Guarantees:
 *   - the room always belongs to the ACTIVE college and to a live building
 *     of the SAME college (contextual foreign-key guard, re-checked here)
 *   - `hostel_id` is derived from the parent building — never trusted from
 *     request input — so the denormalized hierarchy always matches reality
 *   - the parent building (and implied hostel) is fixed at creation; rooms
 *     are never reparented
 *   - `room_number` is unique within the building (and college) and stays
 *     reserved on archived records
 *   - `capacity` is a positive integer; lowering it below the room's current
 *     active bed count is refused
 *   - a `floor`, when provided, must fit the building's declared floors
 *   - a room that still has live beds cannot be deleted
 *   - transactions, per-college serialization and audit on every mutation
 */
class HostelRoomService
{
    private const DUPLICATE_NUMBER_MESSAGE = 'A room with this room number already exists in this building (including archived records).';

    private const FOREIGN_BUILDING_MESSAGE = 'The selected building / block does not belong to the active college.';

    private const IMMUTABLE_BUILDING_MESSAGE = 'A room cannot be moved to another building. Create a new room in the target building instead.';

    private const CAPACITY_TOO_LOW_MESSAGE = 'Capacity cannot be smaller than the number of beds currently in the room.';

    private const FLOOR_OUT_OF_RANGE_MESSAGE = 'This building has fewer floors; the room floor must not exceed the building\'s floor count.';

    private const IN_USE_MESSAGE = 'This room has beds and cannot be deleted. Delete or archive the beds first, or mark the room inactive.';

    private const AUDITED = ['room_number', 'floor', 'room_type', 'capacity', 'description', 'status'];

    private const OPTIONAL = ['floor', 'room_type', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload; `building_id` is the chosen parent.
     */
    public function create(College $college, array $data, User $actor): HostelRoom
    {
        return DB::transaction(function () use ($college, $data, $actor): HostelRoom {
            $this->lockCollege($college);

            $building = $this->requireBuildingOfCollege($college->getKey(), (int) $data['building_id']);

            $room = new HostelRoom([
                'college_id' => $college->getKey(),
                // The hostel is derived from the parent building — never from
                // request input — so the denormalized hierarchy always matches.
                'hostel_id' => $building->hostel_id,
                'building_id' => $building->id,
                'room_number' => $data['room_number'],
                'capacity' => (int) $data['capacity'],
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $room->{$field} = $this->optional($data[$field] ?? null);
            }

            $this->assertFloorWithinBuilding($room, $building);
            $this->assertUniqueIdentifiers($room);

            try {
                $room->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['room_number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            $this->audit->record('hostel_rooms.created', $room, [], $this->snapshot($room));

            return $room->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(HostelRoom $room, array $data, User $actor): HostelRoom
    {
        $this->assertTenant($room);

        return DB::transaction(function () use ($room, $data, $actor): HostelRoom {
            $this->lockWithCollege($room);

            if (array_key_exists('building_id', $data) && (int) $data['building_id'] !== (int) $room->building_id) {
                throw ValidationException::withMessages(['building_id' => self::IMMUTABLE_BUILDING_MESSAGE]);
            }

            // The parent is immutable, so archived buildings may stay as
            // historical anchors: only same-college ownership is required.
            $building = HostelBuilding::withoutGlobalScope(CollegeScope::class)
                ->whereKey($room->building_id)
                ->where('college_id', $room->college_id)
                ->withTrashed()
                ->firstOrFail();

            $old = $this->snapshot($room);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $room->{$field} = match (true) {
                    in_array($field, self::OPTIONAL, true) => $this->optional($data[$field]),
                    $field === 'capacity' => (int) $data[$field],
                    default => $data[$field],
                };
            }

            $room->updated_by = $actor->getKey();

            $this->assertFloorWithinBuilding($room, $building);
            $this->assertCapacityAgainstBeds($room);
            $this->assertUniqueIdentifiers($room);

            try {
                $room->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['room_number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            $this->audit->record('hostel_rooms.updated', $room, $old, $this->snapshot($room));

            return $room->refresh();
        });
    }

    /**
     * Soft delete, refused while live beds reference the room. Historical
     * beds keep their room reachable through the archived record.
     */
    public function delete(HostelRoom $room, User $actor): void
    {
        $this->assertTenant($room);

        DB::transaction(function () use ($room): void {
            $this->lockWithCollege($room);

            if ($room->beds()->exists()) {
                throw ValidationException::withMessages(['room' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $this->snapshot($room);
            $room->delete();

            $this->audit->record('hostel_rooms.deleted', $room, $snapshot, []);
        });
    }

    /**
     * Resolve the parent building as a live row of the given college;
     * anything else (foreign tenant, archived, missing) is a validation
     * error — the composite FK would catch it as well, but at the cost of a
     * raw database exception.
     */
    private function requireBuildingOfCollege(int $collegeId, int $buildingId): HostelBuilding
    {
        $building = HostelBuilding::withoutGlobalScope(CollegeScope::class)
            ->whereKey($buildingId)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->first();

        if (! $building) {
            throw ValidationException::withMessages(['building_id' => self::FOREIGN_BUILDING_MESSAGE]);
        }

        return $building;
    }

    private function assertFloorWithinBuilding(HostelRoom $room, HostelBuilding $building): void
    {
        if ($room->floor !== null && $building->floors !== null && $room->floor > $building->floors) {
            throw ValidationException::withMessages(['floor' => self::FLOOR_OUT_OF_RANGE_MESSAGE]);
        }
    }

    /**
     * A capacity may never drop below the number of live beds the room
     * already holds (creation starts at zero beds, so nothing to check then).
     */
    private function assertCapacityAgainstBeds(HostelRoom $room): void
    {
        if (! $room->exists) {
            return;
        }

        if ($room->capacity < $room->beds()->count()) {
            throw ValidationException::withMessages(['capacity' => self::CAPACITY_TOO_LOW_MESSAGE]);
        }
    }

    private function assertUniqueIdentifiers(HostelRoom $room): void
    {
        // Reserved on archived records as well.
        $duplicateNumber = HostelRoom::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $room->college_id)
            ->where('building_id', $room->building_id)
            ->where('room_number', $room->room_number)
            ->when($room->exists, fn ($query) => $query->whereKeyNot($room->getKey()))
            ->exists();

        if ($duplicateNumber) {
            throw ValidationException::withMessages(['room_number' => self::DUPLICATE_NUMBER_MESSAGE]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(HostelRoom $room): array
    {
        return $room->only(self::AUDITED) + [
            'hostel_id' => (int) $room->hostel_id,
            'building_id' => (int) $room->building_id,
        ];
    }

    private function optional(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : (trim((string) $value) ?: null);
    }

    private function assertTenant(HostelRoom $room): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $room->college_id === (int) $collegeId, 403);
    }

    private function lockCollege(College $college): void
    {
        College::query()->whereKey($college->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockWithCollege(HostelRoom $room): void
    {
        College::query()->whereKey($room->college_id)->lockForUpdate()->firstOrFail();
    }
}
