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
        // Phase 2
        'hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update', 'hostel_allocations.delete',
        'hostel_fees.view', 'hostel_fees.create', 'hostel_fees.update', 'hostel_fees.delete', 'hostel_fees.collect',
        // Phase 3 — attendance and read-only reports. Visitors and later modules are not included.
        'hostel_attendance.view', 'hostel_attendance.create', 'hostel_attendance.update', 'hostel_attendance.delete',
        'hostel_reports.view',
    ];

    /** Phase 2 permissions only */
    private const HOSTEL_PHASE2_PERMISSIONS = [
        'hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update', 'hostel_allocations.delete',
        'hostel_fees.view', 'hostel_fees.create', 'hostel_fees.update', 'hostel_fees.delete', 'hostel_fees.collect',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostelAllocation(\App\Models\College $college, ?\App\Models\StudentEnrollment $enrollment = null, ?\App\Models\HostelBed $bed = null, array $overrides = []): \App\Models\HostelAllocation
    {
        if ($enrollment === null) {
            $yearOverride = $overrides['academic_year_id'] ?? null;
            if ($yearOverride instanceof \App\Models\AcademicYear) {
                $academicYear = $yearOverride;
            } elseif (is_int($yearOverride)) {
                $academicYear = \App\Models\AcademicYear::find($yearOverride) ?? \App\Models\AcademicYear::create([
                    'college_id' => $college->id,
                    'name' => 'Year '.Str::upper(Str::random(4)),
                    'code' => Str::upper(Str::random(6)),
                    'starts_on' => '2026-07-01',
                    'ends_on' => '2027-06-30',
                    'status' => 'active',
                ]);
            } else {
                $academicYear = \App\Models\AcademicYear::create([
                    'college_id' => $college->id,
                    'name' => 'Year '.Str::upper(Str::random(4)),
                    'code' => Str::upper(Str::random(6)),
                    'starts_on' => '2026-07-01',
                    'ends_on' => '2027-06-30',
                    'status' => 'active',
                ]);
            }
            $student = \App\Models\Student::create([
                'college_id' => $college->id,
                'student_number' => 'STU-'.Str::upper(Str::random(4)),
                'first_name' => 'Test',
                'last_name' => 'Student',
                'status' => 'active',
            ]);
            $enrollment = \App\Models\StudentEnrollment::create([
                'college_id' => $college->id,
                'student_id' => $student->id,
                'academic_year_id' => $academicYear->id,
                'enrollment_number' => 'ENR-'.Str::upper(Str::random(4)),
                'enrollment_date' => now()->toDateString(),
                'status' => 'active',
            ]);
        }

        // Load academicYear without tenant scope — helpers run outside HTTP requests where CollegeScope would return 1=0.
        if (! isset($academicYear)) {
            $academicYear = \App\Models\AcademicYear::withoutGlobalScopes()
                ->where('id', $enrollment->academic_year_id)
                ->where('college_id', $college->id)
                ->first()
                ?? \App\Models\AcademicYear::create([
                    'college_id' => $college->id,
                    'name' => 'Year '.Str::upper(Str::random(4)),
                    'code' => Str::upper(Str::random(6)),
                    'starts_on' => '2026-07-01',
                    'ends_on' => '2027-06-30',
                    'status' => 'active',
                ]);
        }

        // Ensure enrollment has its academicYear relation set (avoid null due to CollegeScope outside tenant)
        $enrollment->setRelation('academicYear', $academicYear);

        $bed ??= $this->makeHostelBed($college);

        $academicYearId = $overrides['academic_year_id'] ?? $enrollment->academic_year_id;
        if ($academicYearId instanceof \App\Models\AcademicYear) {
            $academicYearId = $academicYearId->id;
            // If override is a model, use that model as the canonical year
            $academicYear = $overrides['academic_year_id'];
        }

        $allocation = \App\Models\HostelAllocation::create(array_merge([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $academicYearId,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->toDateString(),
            'vacated_date' => null,
            'status' => \App\Models\HostelAllocation::STATUS_ACTIVE,
            'remarks' => null,
        ], $overrides));

        // Set academicYear relation on allocation so $allocation->academicYear does not hit CollegeScope with empty tenant
        $allocation->setRelation('academicYear', $academicYear);

        // Keep bed occupancy consistent with allocation status (source of truth is allocation, but bed status is synchronized)
        if (($allocation->status ?? \App\Models\HostelAllocation::STATUS_ACTIVE) === \App\Models\HostelAllocation::STATUS_ACTIVE) {
            $bed->status = \App\Models\HostelBed::STATUS_OCCUPIED;
            $bed->save();
        } elseif (in_array($allocation->status, [\App\Models\HostelAllocation::STATUS_VACATED, \App\Models\HostelAllocation::STATUS_CANCELLED], true)) {
            // Ensure bed is available when allocation is not active (unless another active allocation exists)
            $hasActive = \App\Models\HostelAllocation::withoutGlobalScopes()
                ->where('college_id', $college->id)
                ->where('hostel_bed_id', $bed->id)
                ->where('status', \App\Models\HostelAllocation::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->whereKeyNot($allocation->id)
                ->exists();
            if (! $hasActive) {
                $bed->status = \App\Models\HostelBed::STATUS_AVAILABLE;
                $bed->save();
            }
        }

        return $allocation;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function allocationPayload(?\App\Models\StudentEnrollment $enrollment = null, ?\App\Models\AcademicYear $year = null, ?\App\Models\Hostel $hostel = null, ?\App\Models\HostelBuilding $building = null, ?\App\Models\HostelRoom $room = null, ?\App\Models\HostelBed $bed = null, array $overrides = []): array
    {
        // If bed provided, derive hierarchy
        if ($bed) {
            $room ??= $bed->room;
            $building ??= $bed->building;
            $hostel ??= $bed->hostel;
        }

        return array_merge([
            'student_enrollment_id' => $enrollment?->id ?? 1,
            'academic_year_id' => $year?->id ?? 1,
            'hostel_id' => $hostel?->id ?? 1,
            'hostel_building_id' => $building?->id ?? 1,
            'hostel_room_id' => $room?->id ?? 1,
            'hostel_bed_id' => $bed?->id ?? 1,
            'allocation_date' => now()->format('Y-m-d'),
            'status' => \App\Models\HostelAllocation::STATUS_ACTIVE,
            'remarks' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostelFeeStructure(\App\Models\College $college, ?\App\Models\AcademicYear $year = null, array $overrides = []): \App\Models\HostelFeeStructure
    {
        if ($year === null) {
            $year = \App\Models\AcademicYear::create([
                'college_id' => $college->id,
                'name' => 'Year '.Str::upper(Str::random(4)),
                'code' => Str::upper(Str::random(6)),
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
                'status' => 'active',
            ]);
        } else {
            // Ensure year is fresh and belongs to college — load without scope if needed
            $year = \App\Models\AcademicYear::withoutGlobalScopes()
                ->where('id', $year->id)
                ->where('college_id', $college->id)
                ->first() ?? $year;
        }

        return \App\Models\HostelFeeStructure::create(array_merge([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Hostel Fee '.Str::upper(Str::random(4)),
            'code' => 'HF-'.Str::upper(Str::random(4)),
            'amount' => 5000,
            'frequency' => 'yearly',
            'status' => \App\Models\HostelFeeStructure::STATUS_ACTIVE,
            'description' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHostelFeeAssignment(\App\Models\College $college, ?\App\Models\HostelAllocation $allocation = null, ?\App\Models\HostelFeeStructure $structure = null, array $overrides = []): \App\Models\HostelFeeAssignment
    {
        $allocation ??= $this->makeHostelAllocation($college);

        // Resolve academic year for structure — $allocation->academicYear may be null outside tenant context, so fallback to academic_year_id
        $allocationYear = $allocation->getRelation('academicYear') ?? $allocation->academicYear;
        if (! $allocationYear) {
            $allocationYear = \App\Models\AcademicYear::withoutGlobalScopes()
                ->where('id', $allocation->academic_year_id)
                ->where('college_id', $college->id)
                ->first();
        }
        $structure ??= $this->makeHostelFeeStructure($college, $allocationYear);

        return \App\Models\HostelFeeAssignment::create(array_merge([
            'college_id' => $college->id,
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'academic_year_id' => $allocation->academic_year_id,
            'assigned_amount' => $structure->amount,
            'effective_from' => now()->format('Y-m-d'),
            'effective_until' => null,
            'status' => \App\Models\HostelFeeAssignment::STATUS_ACTIVE,
            'remarks' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function feeStructurePayload(\App\Models\AcademicYear $year, array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => $year->id,
            'name' => 'Hostel Fee',
            'code' => 'HF-'.Str::upper(Str::random(4)),
            'amount' => 5000,
            'frequency' => 'yearly',
            'status' => \App\Models\HostelFeeStructure::STATUS_ACTIVE,
            'description' => 'Hostel accommodation fee',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function hostelFeeAssignmentPayload(\App\Models\HostelAllocation $allocation, \App\Models\HostelFeeStructure $structure, array $overrides = []): array
    {
        return array_merge([
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => now()->format('Y-m-d'),
            'status' => \App\Models\HostelFeeAssignment::STATUS_ACTIVE,
        ], $overrides);
    }
}
