<?php

namespace Tests\Feature\Hostel;

use App\Models\College;
use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for the Hostel Management (Phase 1) tests.
 *
 * Reuses the project-wide fixtures (college, RBAC users, super admin) and adds
 * only what the Hostel module owns: hostels, buildings / blocks, rooms and
 * beds. Fixtures are created directly (not through HTTP) so each test
 * exercises one behaviour, and are always stamped with an explicit college_id.
 */
trait HostelTestHelpers
{
    use ExamAttendanceTestHelpers;

    /** Every Hostel permission slug seeded for Phase 1. */
    private const HOSTEL_PERMISSIONS = [
        'hostel_dashboard.view',
        'hostels.view', 'hostels.create', 'hostels.update', 'hostels.delete',
        'hostel_buildings.view', 'hostel_buildings.create', 'hostel_buildings.update', 'hostel_buildings.delete',
        'hostel_rooms.view', 'hostel_rooms.create', 'hostel_rooms.update', 'hostel_rooms.delete',
        'hostel_beds.view', 'hostel_beds.create', 'hostel_beds.update', 'hostel_beds.delete',
    ];

    /**
     * Run a callback with the tenant context bound to $college.
     *
     * Every Hostel model carries CollegeScope, which resolves to
     * `whereRaw('1 = 0')` when no tenant is active. Assertions that read these
     * models directly (outside an HTTP request) therefore have to pin the
     * tenant explicitly — inside a request the `tenant` middleware does it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostel(College $college, array $overrides = []): Hostel
    {
        return Hostel::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Hostel '.Str::upper(Str::random(6)),
            'code' => 'H-'.Str::upper(Str::random(4)),
            'hostel_type' => 'mixed',
            'gender' => 'any',
            'address' => null,
            'description' => null,
            'status' => Hostel::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostelBuilding(College $college, ?Hostel $hostel = null, array $overrides = []): HostelBuilding
    {
        $hostel ??= $this->makeHostel($college);

        $building = HostelBuilding::create(array_merge([
            'college_id' => $college->id,
            'hostel_id' => $hostel->id,
            'name' => 'Block '.Str::upper(Str::random(4)),
            'code' => 'B-'.Str::upper(Str::random(4)),
            'floors' => 3,
            'description' => null,
            'status' => HostelBuilding::STATUS_ACTIVE,
        ], $overrides));

        // Keep denormalized consistency when a custom hostel_id is supplied.
        return $building;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostelRoom(College $college, ?HostelBuilding $building = null, array $overrides = []): HostelRoom
    {
        $building ??= $this->makeHostelBuilding($college);
        $building->loadMissing('hostel');

        return HostelRoom::create(array_merge([
            'college_id' => $building->college_id,
            'hostel_id' => $building->hostel_id,
            'building_id' => $building->id,
            'room_number' => Str::upper(Str::random(2)).'-'.random_int(100, 999),
            'floor' => 1,
            'room_type' => 'Double',
            'capacity' => 2,
            'description' => null,
            'status' => HostelRoom::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostelBed(College $college, ?HostelRoom $room = null, array $overrides = []): HostelBed
    {
        $room ??= $this->makeHostelRoom($college);

        $number = $overrides['bed_number'] ?? (string) (HostelBed::withoutGlobalScopes()->where('room_id', $room->id)->count() + 1);

        return HostelBed::create(array_merge([
            'college_id' => $room->college_id,
            'hostel_id' => $room->hostel_id,
            'building_id' => $room->building_id,
            'room_id' => $room->id,
            'bed_number' => $number,
            'status' => HostelBed::STATUS_AVAILABLE,
            'description' => null,
        ], $overrides));
    }

    /**
     * The HTTP payload for creating/updating a hostel.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function hostelPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Boys Hostel',
            'code' => 'BH-A',
            'hostel_type' => 'boys',
            'gender' => 'male',
            'status' => Hostel::STATUS_ACTIVE,
            'address' => '12 Lake View Road',
            'description' => 'Main gents hostel',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildingPayload(Hostel $hostel, array $overrides = []): array
    {
        return array_merge([
            'hostel_id' => $hostel->id,
            'name' => 'North Block',
            'code' => 'NB',
            'floors' => 3,
            'status' => HostelBuilding::STATUS_ACTIVE,
            'description' => 'Three-storey north wing',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function roomPayload(HostelBuilding $building, array $overrides = []): array
    {
        return array_merge([
            'building_id' => $building->id,
            'room_number' => '101',
            'floor' => 1,
            'room_type' => 'Double',
            'capacity' => 2,
            'status' => HostelRoom::STATUS_ACTIVE,
            'description' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bedPayload(HostelRoom $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'bed_number' => '1',
            'status' => HostelBed::STATUS_AVAILABLE,
            'description' => null,
        ], $overrides);
    }
}
