<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelBedService — CRUD + integrity rules for hostel beds
 * (Hostel Management, Phase 1).
 *
 * Guarantees:
 *   - the bed always belongs to the ACTIVE college and to a live room of the
 *     SAME college (contextual foreign-key guard, re-checked here)
 *   - `hostel_id` / `building_id` are derived from the parent room — never
 *     trusted from request input
 *   - the parent room is fixed at creation; beds are never reparented
 *   - `bed_number` is unique within the room (and college) and stays reserved
 *     on archived records, so future allocation/history references can never
 *     become ambiguous
 *   - a room's active bed count never exceeds its capacity
 *   - an occupied bed cannot be deleted (mark it available or inactive
 *     first); from Phase 2, Hostel Allocation history hardens this further
 *     and becomes the source of truth for occupancy
 *   - transactions, per-college serialization and audit on every mutation
 */
class HostelBedService
{
    private const DUPLICATE_NUMBER_MESSAGE = 'A bed with this bed number already exists in this room (including archived records).';

    private const FOREIGN_ROOM_MESSAGE = 'The selected room does not belong to the active college.';

    private const IMMUTABLE_ROOM_MESSAGE = 'A bed cannot be moved to another room. Create a new bed in the target room instead.';

    private const ROOM_FULL_MESSAGE = 'This room has reached its capacity; increase the room capacity before adding more beds.';

    private const OCCUPIED_DELETE_MESSAGE = 'This bed is occupied. Mark it available or inactive before deleting it.';

    private const AUDITED = ['bed_number', 'status', 'description'];

    private const OPTIONAL = ['description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload; `room_id` is the chosen parent.
     */
    public function create(College $college, array $data, User $actor): HostelBed
    {
        return DB::transaction(function () use ($college, $data, $actor): HostelBed {
            $this->lockCollege($college);

            $room = $this->requireRoomOfCollege($college->getKey(), (int) $data['room_id']);

            $bed = new HostelBed([
                'college_id' => $college->getKey(),
                // The hostel and building are derived from the parent room —
                // never from request input — so the hierarchy always matches.
                'hostel_id' => $room->hostel_id,
                'building_id' => $room->building_id,
                'room_id' => $room->id,
                'bed_number' => $data['bed_number'],
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $bed->{$field} = $this->optional($data[$field] ?? null);
            }

            $this->assertRoomCapacity($room, $bed);
            $this->assertUniqueIdentifiers($bed);

            try {
                $bed->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['bed_number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            $this->audit->record('hostel_beds.created', $bed, [], $this->snapshot($bed));

            return $bed->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(HostelBed $bed, array $data, User $actor): HostelBed
    {
        $this->assertTenant($bed);

        return DB::transaction(function () use ($bed, $data, $actor): HostelBed {
            $this->lockWithCollege($bed);

            if (array_key_exists('room_id', $data) && (int) $data['room_id'] !== (int) $bed->room_id) {
                throw ValidationException::withMessages(['room_id' => self::IMMUTABLE_ROOM_MESSAGE]);
            }

            $old = $this->snapshot($bed);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $bed->{$field} = in_array($field, self::OPTIONAL, true)
                    ? $this->optional($data[$field])
                    : $data[$field];
            }

            $bed->updated_by = $actor->getKey();

            $this->assertUniqueIdentifiers($bed);

            try {
                $bed->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['bed_number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            $this->audit->record('hostel_beds.updated', $bed, $old, $this->snapshot($bed));

            return $bed->refresh();
        });
    }

    /**
     * Soft delete. An occupied bed is refused in Phase 1 (mark it available
     * or inactive first); once Hostel Allocation history exists (Phase 2),
     * allocation references will hard-block deletion entirely. Archived beds
     * keep their identifiers reserved either way.
     */
    public function delete(HostelBed $bed, User $actor): void
    {
        $this->assertTenant($bed);

        DB::transaction(function () use ($bed): void {
            $this->lockWithCollege($bed);

            if ($bed->isOccupied()) {
                throw ValidationException::withMessages(['bed' => self::OCCUPIED_DELETE_MESSAGE]);
            }

            $snapshot = $this->snapshot($bed);
            $bed->delete();

            $this->audit->record('hostel_beds.deleted', $bed, $snapshot, []);
        });
    }

    /**
     * Resolve the parent room as a live row of the given college.
     */
    private function requireRoomOfCollege(int $collegeId, int $roomId): HostelRoom
    {
        $room = HostelRoom::withoutGlobalScope(CollegeScope::class)
            ->whereKey($roomId)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->first();

        if (! $room) {
            throw ValidationException::withMessages(['room_id' => self::FOREIGN_ROOM_MESSAGE]);
        }

        return $room;
    }

    /**
     * A bed may only be added while the room has spare capacity (live beds
     * count is below the room's capacity).
     */
    private function assertRoomCapacity(HostelRoom $room, HostelBed $bed): void
    {
        if ($bed->exists) {
            return;
        }

        if ($room->beds()->count() >= $room->capacity) {
            throw ValidationException::withMessages(['room_id' => self::ROOM_FULL_MESSAGE]);
        }
    }

    private function assertUniqueIdentifiers(HostelBed $bed): void
    {
        // Reserved on archived records as well.
        $duplicateNumber = HostelBed::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $bed->college_id)
            ->where('room_id', $bed->room_id)
            ->where('bed_number', $bed->bed_number)
            ->when($bed->exists, fn ($query) => $query->whereKeyNot($bed->getKey()))
            ->exists();

        if ($duplicateNumber) {
            throw ValidationException::withMessages(['bed_number' => self::DUPLICATE_NUMBER_MESSAGE]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(HostelBed $bed): array
    {
        return $bed->only(self::AUDITED) + [
            'hostel_id' => (int) $bed->hostel_id,
            'building_id' => (int) $bed->building_id,
            'room_id' => (int) $bed->room_id,
        ];
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(HostelBed $bed): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $bed->college_id === (int) $collegeId, 403);
    }

    private function lockCollege(College $college): void
    {
        College::query()->whereKey($college->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockWithCollege(HostelBed $bed): void
    {
        College::query()->whereKey($bed->college_id)->lockForUpdate()->firstOrFail();
    }
}
