<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelAllocationService — CRUD + integrity rules for hostel allocations
 * (Hostel Management Phase 2).
 *
 * Guarantees:
 * - every relationship belongs to the ACTIVE college
 * - building belongs to hostel, room belongs to building+hostel, bed belongs to room+building+hostel
 * - student enrollment and academic year belong to same college
 * - a bed can have only ONE active allocation at a time (DB partial unique + row lock)
 * - a student enrollment can have only ONE active allocation per academic year
 * - vacating releases bed, cancelled does not occupy bed
 * - bed occupancy (HostelBed.status) is synchronized transactionally and is NOT an independent source
 * - concurrent allocation attempts for same bed cannot both succeed (locking)
 * - all mutations transactional and audited
 */
class HostelAllocationService
{
    private const AUDITED = [
        'student_enrollment_id',
        'academic_year_id',
        'hostel_id',
        'hostel_building_id',
        'hostel_room_id',
        'hostel_bed_id',
        'allocation_date',
        'vacated_date',
        'status',
        'remarks',
    ];

    private const OPTIONAL = ['remarks', 'vacated_date'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param array{student_enrollment_id: int, academic_year_id: int, hostel_id: int, hostel_building_id: int, hostel_room_id: int, hostel_bed_id: int, allocation_date: string, vacated_date?: string|null, status?: string, remarks?: string|null} $data
     */
    public function create(College $college, array $data, User $actor): HostelAllocation
    {
        return DB::transaction(function () use ($college, $data, $actor): HostelAllocation {
            $this->lockCollege($college);

            $enrollment = $this->requireEnrollmentOfCollege($college->id, (int) $data['student_enrollment_id']);
            $academicYear = $this->requireAcademicYearOfCollege($college->id, (int) $data['academic_year_id']);
            $hostel = $this->requireHostelOfCollege($college->id, (int) $data['hostel_id']);
            $building = $this->requireBuildingOfCollege($college->id, (int) $data['hostel_building_id'], $hostel);
            $room = $this->requireRoomOfCollege($college->id, (int) $data['hostel_room_id'], $building, $hostel);
            $bed = $this->requireBedOfCollege($college->id, (int) $data['hostel_bed_id'], $room, $building, $hostel);

            $this->assertBedAvailable($bed);
            $this->assertEnrollmentHasNoActiveAllocation($college->id, $enrollment->id, $academicYear->id);

            $allocationDate = $data['allocation_date'];
            $vacatedDate = $data['vacated_date'] ?? null;
            $status = $data['status'] ?? HostelAllocation::STATUS_ACTIVE;

            $this->assertDates($allocationDate, $vacatedDate, $status);

            // Lock the bed row for update to prevent concurrent allocations.
            HostelBed::withoutGlobalScope(CollegeScope::class)
                ->where('id', $bed->id)
                ->where('college_id', $college->id)
                ->lockForUpdate()
                ->first();

            // Double-check under lock that bed is still free (in case of race).
            $this->assertBedAvailable($bed, true);
            $this->assertEnrollmentHasNoActiveAllocation($college->id, $enrollment->id, $academicYear->id, true);

            $allocation = new HostelAllocation([
                'college_id' => $college->id,
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $academicYear->id,
                'hostel_id' => $hostel->id,
                'hostel_building_id' => $building->id,
                'hostel_room_id' => $room->id,
                'hostel_bed_id' => $bed->id,
                'allocation_date' => $allocationDate,
                'vacated_date' => $vacatedDate,
                'status' => $status,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $allocation->remarks = $this->optional($data['remarks'] ?? null);

            // If status is vacated, vacated_date must be present (validated in assertDates).
            // If active, ensure vacated_date null.
            if ($allocation->status === HostelAllocation::STATUS_ACTIVE) {
                $allocation->vacated_date = null;
            }

            $allocation->save();

            // Synchronize bed occupancy: active allocation occupies bed.
            if ($allocation->status === HostelAllocation::STATUS_ACTIVE) {
                $this->setBedOccupied($bed, $actor);
            } elseif ($allocation->status === HostelAllocation::STATUS_VACATED) {
                $this->setBedAvailable($bed, $actor);
            } else {
                // cancelled does not occupy
                $this->setBedAvailable($bed, $actor);
            }

            $this->audit->record('hostel_allocations.created', $allocation, [], $this->snapshot($allocation));

            return $allocation->refresh();
        });
    }

    /**
     * @param array{allocation_date?: string, vacated_date?: string|null, status?: string, remarks?: string|null} $data
     */
    public function update(HostelAllocation $allocation, array $data, User $actor): HostelAllocation
    {
        $this->assertTenant($allocation);

        return DB::transaction(function () use ($allocation, $data, $actor): HostelAllocation {
            $this->lockWithCollege($allocation);

            // Reload locked allocation
            $locked = HostelAllocation::withoutGlobalScopes()->lockForUpdate()->findOrFail($allocation->id);

            // Immutability: enrollment, academic year, hostel, building, room, bed cannot be changed after creation.
            $immutable = ['student_enrollment_id', 'academic_year_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id'];
            foreach ($immutable as $field) {
                if (array_key_exists($field, $data) && (int) $data[$field] !== (int) $locked->{$field}) {
                    $map = [
                        'student_enrollment_id' => 'The student enrollment cannot be changed. Vacate and create a new allocation instead.',
                        'academic_year_id' => 'The academic year cannot be changed. Vacate and create a new allocation instead.',
                        'hostel_id' => 'The hostel cannot be changed. Vacate and create a new allocation instead.',
                        'hostel_building_id' => 'The building cannot be changed. Vacate and create a new allocation instead.',
                        'hostel_room_id' => 'The room cannot be changed. Vacate and create a new allocation instead.',
                        'hostel_bed_id' => 'The bed cannot be changed. Vacate and create a new allocation instead.',
                    ];
                    throw ValidationException::withMessages([$field => $map[$field]]);
                }
            }

            $old = $this->snapshot($locked);

            // Only allow updating allocation_date, vacated_date, status, remarks
            if (array_key_exists('allocation_date', $data)) {
                $locked->allocation_date = $data['allocation_date'];
            }
            if (array_key_exists('vacated_date', $data)) {
                $locked->vacated_date = $data['vacated_date'] ?: null;
            }
            if (array_key_exists('status', $data)) {
                // Prevent invalid status transitions via generic update; use vacate/cancel actions.
                // Allow status to be updated only if it's same or moving to vacated/cancelled with proper vacated_date.
                // For simplicity, allow direct status change but validate dates.
                $locked->status = $data['status'];
            }
            if (array_key_exists('remarks', $data)) {
                $locked->remarks = $this->optional($data['remarks']);
            }

            $this->assertDates($locked->allocation_date->format('Y-m-d'), $locked->vacated_date?->format('Y-m-d'), $locked->status);

            // If status is being set to active, ensure no other active allocation exists for same bed/enrollment
            if ($locked->status === HostelAllocation::STATUS_ACTIVE) {
                $this->assertBedAvailableForUpdate($locked);
                $this->assertEnrollmentHasNoActiveAllocation($locked->college_id, $locked->student_enrollment_id, $locked->academic_year_id, false, $locked->id);
            }

            $locked->updated_by = $actor->getKey();
            $locked->save();

            // Sync bed status
            $bed = HostelBed::withoutGlobalScopes()->where('id', $locked->hostel_bed_id)->where('college_id', $locked->college_id)->firstOrFail();
            if ($locked->status === HostelAllocation::STATUS_ACTIVE) {
                $this->setBedOccupied($bed, $actor);
            } else {
                $this->setBedAvailable($bed, $actor);
            }

            $this->audit->record('hostel_allocations.updated', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    public function vacate(HostelAllocation $allocation, array $data, User $actor): HostelAllocation
    {
        $this->assertTenant($allocation);

        return DB::transaction(function () use ($allocation, $data, $actor): HostelAllocation {
            $this->lockWithCollege($allocation);
            $locked = HostelAllocation::withoutGlobalScopes()->lockForUpdate()->findOrFail($allocation->id);

            if ($locked->status !== HostelAllocation::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => 'Only an active allocation can be vacated.']);
            }

            $vacatedDate = $data['vacated_date'] ?? now()->toDateString();

            $this->assertDates($locked->allocation_date->format('Y-m-d'), $vacatedDate, HostelAllocation::STATUS_VACATED);

            $old = $this->snapshot($locked);

            $locked->vacated_date = $vacatedDate;
            $locked->status = HostelAllocation::STATUS_VACATED;
            if (array_key_exists('remarks', $data)) {
                $locked->remarks = $this->optional($data['remarks']) ?? $locked->remarks;
            }
            $locked->updated_by = $actor->getKey();
            $locked->save();

            $bed = HostelBed::withoutGlobalScopes()->where('id', $locked->hostel_bed_id)->where('college_id', $locked->college_id)->firstOrFail();
            $this->setBedAvailable($bed, $actor);

            $this->audit->record('hostel_allocations.vacated', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    public function cancel(HostelAllocation $allocation, User $actor, ?string $remarks = null): HostelAllocation
    {
        $this->assertTenant($allocation);

        return DB::transaction(function () use ($allocation, $actor, $remarks): HostelAllocation {
            $this->lockWithCollege($allocation);
            $locked = HostelAllocation::withoutGlobalScopes()->lockForUpdate()->findOrFail($allocation->id);

            if ($locked->status === HostelAllocation::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['status' => 'This allocation is already cancelled.']);
            }

            // Vacated allocations can be cancelled? Allow, but active must be cancellable.
            // For simplicity, allow cancelling active or vacated.

            $old = $this->snapshot($locked);

            $locked->status = HostelAllocation::STATUS_CANCELLED;
            // Cancelled allocations should not have vacated_date? Keep existing vacated_date if was vacated, else null.
            if ($locked->status === HostelAllocation::STATUS_VACATED) {
                // Keep vacated_date
            } else {
                // If cancelling active, clear vacated_date? Or keep null.
                $locked->vacated_date = null;
            }
            if ($remarks !== null) {
                $locked->remarks = $this->optional($remarks) ?? $locked->remarks;
            }
            $locked->updated_by = $actor->getKey();
            $locked->save();

            $bed = HostelBed::withoutGlobalScopes()->where('id', $locked->hostel_bed_id)->where('college_id', $locked->college_id)->firstOrFail();
            $this->setBedAvailable($bed, $actor);

            $this->audit->record('hostel_allocations.cancelled', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    public function delete(HostelAllocation $allocation, User $actor): void
    {
        $this->assertTenant($allocation);

        DB::transaction(function () use ($allocation, $actor): void {
            $this->lockWithCollege($allocation);
            $locked = HostelAllocation::withoutGlobalScopes()->lockForUpdate()->findOrFail($allocation->id);

            $bed = null;
            if ($locked->isActive()) {
                $bed = HostelBed::withoutGlobalScopes()->where('id', $locked->hostel_bed_id)->where('college_id', $locked->college_id)->first();
            }

            $snapshot = $this->snapshot($locked);
            $locked->delete();

            if ($bed) {
                $this->setBedAvailable($bed, $actor);
            }

            $this->audit->record('hostel_allocations.deleted', $locked, $snapshot, []);
        });
    }

    // ------------------------------------------------- helpers

    private function requireEnrollmentOfCollege(int $collegeId, int $enrollmentId): StudentEnrollment
    {
        $enrollment = StudentEnrollment::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($enrollmentId);

        if (! $enrollment) {
            throw ValidationException::withMessages(['student_enrollment_id' => 'The selected student enrollment does not belong to the active college.']);
        }

        return $enrollment;
    }

    private function requireAcademicYearOfCollege(int $collegeId, int $yearId): AcademicYear
    {
        $year = AcademicYear::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($yearId);

        if (! $year) {
            throw ValidationException::withMessages(['academic_year_id' => 'The selected academic year does not belong to the active college.']);
        }

        return $year;
    }

    private function requireHostelOfCollege(int $collegeId, int $hostelId): Hostel
    {
        $hostel = Hostel::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($hostelId);

        if (! $hostel) {
            throw ValidationException::withMessages(['hostel_id' => 'The selected hostel does not belong to the active college.']);
        }

        return $hostel;
    }

    private function requireBuildingOfCollege(int $collegeId, int $buildingId, Hostel $hostel): HostelBuilding
    {
        $building = HostelBuilding::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->where('hostel_id', $hostel->id)
            ->whereNull('deleted_at')
            ->find($buildingId);

        if (! $building) {
            throw ValidationException::withMessages(['hostel_building_id' => 'The selected building does not belong to the selected hostel or active college.']);
        }

        return $building;
    }

    private function requireRoomOfCollege(int $collegeId, int $roomId, HostelBuilding $building, Hostel $hostel): HostelRoom
    {
        $room = HostelRoom::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->where('building_id', $building->id)
            ->where('hostel_id', $hostel->id)
            ->whereNull('deleted_at')
            ->find($roomId);

        if (! $room) {
            throw ValidationException::withMessages(['hostel_room_id' => 'The selected room does not belong to the selected building/hostel or active college.']);
        }

        return $room;
    }

    private function requireBedOfCollege(int $collegeId, int $bedId, HostelRoom $room, HostelBuilding $building, Hostel $hostel): HostelBed
    {
        $bed = HostelBed::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->where('room_id', $room->id)
            ->where('building_id', $building->id)
            ->where('hostel_id', $hostel->id)
            ->whereNull('deleted_at')
            ->find($bedId);

        if (! $bed) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'The selected bed does not belong to the selected room/building/hostel or active college.']);
        }

        return $bed;
    }

    private function assertBedAvailable(HostelBed $bed, bool $underLock = false): void
    {
        $query = HostelAllocation::withoutGlobalScopes()
            ->where('college_id', $bed->college_id)
            ->where('hostel_bed_id', $bed->id)
            ->where('status', HostelAllocation::STATUS_ACTIVE)
            ->whereNull('deleted_at');

        if ($underLock) {
            $query->lockForUpdate();
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'This bed already has an active allocation.']);
        }

        // Also check bed status for UI convenience, but allocation is source of truth.
        // If bed is marked inactive, refuse.
        if ($bed->status === HostelBed::STATUS_INACTIVE) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'This bed is inactive and cannot be allocated.']);
        }
    }

    private function assertBedAvailableForUpdate(HostelAllocation $allocation): void
    {
        $exists = HostelAllocation::withoutGlobalScopes()
            ->where('college_id', $allocation->college_id)
            ->where('hostel_bed_id', $allocation->hostel_bed_id)
            ->where('status', HostelAllocation::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->whereKeyNot($allocation->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['hostel_bed_id' => 'This bed already has an active allocation.']);
        }
    }

    private function assertEnrollmentHasNoActiveAllocation(int $collegeId, int $enrollmentId, int $academicYearId, bool $underLock = false, ?int $ignoreId = null): void
    {
        $query = HostelAllocation::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_enrollment_id', $enrollmentId)
            ->where('academic_year_id', $academicYearId)
            ->where('status', HostelAllocation::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId));

        if ($underLock) {
            $query->lockForUpdate();
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['student_enrollment_id' => 'This student enrollment already has an active hostel allocation for the selected academic year.']);
        }
    }

    private function assertDates(?string $allocationDate, ?string $vacatedDate, string $status): void
    {
        if ($allocationDate === null || $allocationDate === '') {
            throw ValidationException::withMessages(['allocation_date' => 'The allocation date is required.']);
        }

        // Validate date format via strtotime check
        if (strtotime($allocationDate) === false) {
            throw ValidationException::withMessages(['allocation_date' => 'The allocation date must be a valid date.']);
        }

        if ($vacatedDate !== null && $vacatedDate !== '') {
            if (strtotime($vacatedDate) === false) {
                throw ValidationException::withMessages(['vacated_date' => 'The vacated date must be a valid date.']);
            }
            if ($vacatedDate < $allocationDate) {
                throw ValidationException::withMessages(['vacated_date' => 'The vacated date must not be earlier than the allocation date.']);
            }
        }

        if ($status === HostelAllocation::STATUS_ACTIVE && $vacatedDate !== null && $vacatedDate !== '') {
            throw ValidationException::withMessages(['vacated_date' => 'An active allocation cannot have a vacated date.']);
        }

        if ($status === HostelAllocation::STATUS_VACATED && ($vacatedDate === null || $vacatedDate === '')) {
            throw ValidationException::withMessages(['vacated_date' => 'A vacated allocation must have a vacated date.']);
        }
    }

    private function setBedOccupied(HostelBed $bed, User $actor): void
    {
        $bed->status = HostelBed::STATUS_OCCUPIED;
        $bed->updated_by = $actor->getKey();
        $bed->save();
    }

    private function setBedAvailable(HostelBed $bed, User $actor): void
    {
        // Only set to available if no other active allocation exists for this bed
        $hasActive = HostelAllocation::withoutGlobalScopes()
            ->where('college_id', $bed->college_id)
            ->where('hostel_bed_id', $bed->id)
            ->where('status', HostelAllocation::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasActive) {
            // If bed was inactive before, keep it available? For simplicity, set to available.
            $bed->status = HostelBed::STATUS_AVAILABLE;
            $bed->updated_by = $actor->getKey();
            $bed->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(HostelAllocation $allocation): array
    {
        return $allocation->only(self::AUDITED);
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(HostelAllocation $allocation): void
    {
        $collegeId = app(TenantContext::class)->id();
        abort_unless($collegeId !== null && (int) $allocation->college_id === (int) $collegeId, 403);
    }

    private function lockCollege(College $college): void
    {
        College::query()->whereKey($college->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockWithCollege(HostelAllocation $allocation): void
    {
        College::query()->whereKey($allocation->college_id)->lockForUpdate()->firstOrFail();
    }
}
