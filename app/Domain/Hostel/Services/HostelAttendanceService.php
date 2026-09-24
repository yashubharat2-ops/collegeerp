<?php

namespace App\Domain\Hostel\Services;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelAttendanceService — daily attendance for current hostel residents.
 *
 * HostelAllocation is the source of truth for residency. A new mark is accepted
 * only for an active allocation whose enrollment, academic year and hostel
 * hierarchy all belong to the active college. college_id, the enrollment and
 * every audit column are stamped here — never from the request.
 *
 * One live row per student enrollment and date. Bulk marking is one
 * transaction: every row is validated before anything is written, and a later
 * failure rolls the batch back.
 */
class HostelAttendanceService
{
    /** Enrollment statuses that are not a valid hostel resident. */
    private const INVALID_ENROLLMENT_STATUSES = ['cancelled', 'withdrawn'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{hostel_allocation_id: int, student_enrollment_id?: int|null, attendance_date: string, attendance_status: string, remarks?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): HostelAttendance
    {
        $this->assertActingCollege($college);

        return DB::transaction(function () use ($college, $data, $actor): HostelAttendance {
            $this->lockCollege($college);
            $date = $this->normalizeDate((string) $data['attendance_date']);
            $this->assertNotFuture($date, 'attendance_date');

            $allocation = $this->requireAllocation($college->id, (int) $data['hostel_allocation_id'], 'hostel_allocation_id');
            $this->assertHierarchy($college->id, $allocation, 'hostel_allocation_id');
            $enrollment = $this->requireEnrollment($college->id, (int) $allocation->student_enrollment_id, 'hostel_allocation_id');
            $this->assertCurrentResident($allocation, $enrollment, $date, 'hostel_allocation_id');
            $this->assertEnrollmentMatches($allocation, $data['student_enrollment_id'] ?? null);

            $this->lockEnrollment($college->id, (int) $enrollment->id);
            $this->assertNoDuplicate($college->id, (int) $enrollment->id, $date);

            try {
                $attendance = HostelAttendance::create([
                    'college_id' => $college->id,
                    'student_enrollment_id' => $enrollment->id,
                    'hostel_allocation_id' => $allocation->id,
                    'attendance_date' => $date,
                    'attendance_status' => $this->assertStatus((string) $data['attendance_status'], 'attendance_status'),
                    'remarks' => $this->optional($data['remarks'] ?? null),
                    'marked_at' => now(),
                    'marked_by' => $actor->getKey(),
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'attendance_date' => 'Attendance for this student and date already exists. Correct the existing record instead.',
                ]);
            }

            $this->audit->record('hostel_attendance.created', $attendance, [], $this->snapshot($attendance));

            return $attendance->refresh();
        });
    }

    /**
     * Authorized correction of an existing mark. The resident link is immutable;
     * status, remarks and the date may be corrected. The date must still fall
     * inside the allocation's residency window.
     *
     * @param  array{attendance_date: string, attendance_status: string, remarks?: string|null}  $data
     */
    public function update(HostelAttendance $attendance, array $data, User $actor): HostelAttendance
    {
        $this->assertRecordTenant($attendance);

        return DB::transaction(function () use ($attendance, $data, $actor): HostelAttendance {
            $this->lockCollegeId((int) $attendance->college_id);
            $locked = HostelAttendance::withoutGlobalScopes()->lockForUpdate()->findOrFail($attendance->id);
            $this->assertRecordTenant($locked);

            $date = $this->normalizeDate((string) $data['attendance_date']);
            $this->assertNotFuture($date, 'attendance_date');

            $allocation = $this->requireAllocation((int) $locked->college_id, (int) $locked->hostel_allocation_id, 'attendance_date');
            $this->assertDateWithinResidency($allocation, $date, 'attendance_date');

            $this->lockEnrollment((int) $locked->college_id, (int) $locked->student_enrollment_id);
            $this->assertNoDuplicate((int) $locked->college_id, (int) $locked->student_enrollment_id, $date, (int) $locked->id);

            $old = $this->snapshot($locked);

            try {
                $locked->update([
                    'attendance_date' => $date,
                    'attendance_status' => $this->assertStatus((string) $data['attendance_status'], 'attendance_status'),
                    'remarks' => $this->optional($data['remarks'] ?? null),
                    'marked_at' => now(),
                    'marked_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'attendance_date' => 'Attendance for this student and date already exists.',
                ]);
            }

            $fresh = $locked->refresh();
            $this->audit->record('hostel_attendance.updated', $fresh, $old, $this->snapshot($fresh));

            return $fresh;
        });
    }

    public function delete(HostelAttendance $attendance, User $actor): void
    {
        $this->assertRecordTenant($attendance);

        DB::transaction(function () use ($attendance, $actor): void {
            $locked = HostelAttendance::withoutGlobalScopes()->lockForUpdate()->findOrFail($attendance->id);
            $this->assertRecordTenant($locked);
            $snapshot = $this->snapshot($locked);
            $locked->updated_by = $actor->getKey();
            $locked->save();
            $locked->delete();
            $this->audit->record('hostel_attendance.deleted', $locked, $snapshot, []);
        });
    }

    /**
     * Transactional bulk roll-call for one date.
     *
     * Rows without a status are left untouched. Every submitted row is validated
     * before the first write. An existing mark for that student and date is an
     * authorized correction and requires hostel_attendance.update; otherwise the
     * whole batch is rejected and nothing is stored.
     *
     * @param  array<int, array{hostel_allocation_id?: int, attendance_status?: string|null, remarks?: string|null}>  $records
     * @return array{created: int, updated: int}
     */
    public function markBulk(College $college, string $attendanceDate, array $records, User $actor): array
    {
        $this->assertActingCollege($college);

        return DB::transaction(function () use ($college, $attendanceDate, $records, $actor): array {
            $this->lockCollege($college);
            $date = $this->normalizeDate($attendanceDate);
            $this->assertNotFuture($date, 'attendance_date');

            $submitted = [];
            foreach ($records as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $status = $row['attendance_status'] ?? null;
                if ($status === null || $status === '') {
                    continue;
                }
                $submitted[$index] = $row;
            }

            if ($submitted === []) {
                throw ValidationException::withMessages([
                    'records' => 'Mark at least one resident.',
                ]);
            }

            $allocationIds = collect($submitted)->pluck('hostel_allocation_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $allocations = HostelAllocation::withoutGlobalScopes()
                ->where('college_id', $college->id)
                ->whereNull('deleted_at')
                ->whereIn('id', $allocationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $enrollments = $this->loadRelated(StudentEnrollment::class, $college->id, $allocations->pluck('student_enrollment_id'));
            $this->assertRelatedSet(Hostel::class, $college->id, $allocations->pluck('hostel_id'), 'hostel');
            $this->assertRelatedSet(HostelBuilding::class, $college->id, $allocations->pluck('hostel_building_id'), 'building');
            $this->assertRelatedSet(HostelRoom::class, $college->id, $allocations->pluck('hostel_room_id'), 'room');
            $this->assertRelatedSet(HostelBed::class, $college->id, $allocations->pluck('hostel_bed_id'), 'bed');
            $this->assertRelatedSet(AcademicYear::class, $college->id, $allocations->pluck('academic_year_id'), 'academic year');

            $prepared = [];
            $seenAllocations = [];
            $seenEnrollments = [];

            foreach ($submitted as $index => $row) {
                $field = "records.{$index}.hostel_allocation_id";
                $status = $this->assertStatus((string) $row['attendance_status'], "records.{$index}.attendance_status");
                $allocationId = (int) ($row['hostel_allocation_id'] ?? 0);

                if (isset($seenAllocations[$allocationId])) {
                    throw ValidationException::withMessages([
                        $field => 'This resident is listed more than once in the batch.',
                    ]);
                }
                $seenAllocations[$allocationId] = true;

                /** @var HostelAllocation|null $allocation */
                $allocation = $allocations->get($allocationId);
                if ($allocation === null) {
                    throw ValidationException::withMessages([
                        $field => 'Select a hostel allocation belonging to the active college.',
                    ]);
                }

                $enrollment = $enrollments->get((int) $allocation->student_enrollment_id);
                if ($enrollment === null) {
                    throw ValidationException::withMessages([
                        $field => 'The allocation\'s student enrollment does not belong to the active college.',
                    ]);
                }

                if (isset($seenEnrollments[$enrollment->id])) {
                    throw ValidationException::withMessages([
                        $field => 'Attendance for this student and date is duplicated in the batch.',
                    ]);
                }
                $seenEnrollments[$enrollment->id] = true;

                $this->assertCurrentResident($allocation, $enrollment, $date, $field);

                $prepared[] = [
                    'allocation' => $allocation,
                    'enrollment' => $enrollment,
                    'status' => $status,
                    'remarks' => $this->optional($row['remarks'] ?? null),
                ];
            }

            $enrollmentIds = array_keys($seenEnrollments);
            sort($enrollmentIds);
            foreach ($enrollmentIds as $enrollmentId) {
                $this->lockEnrollment($college->id, (int) $enrollmentId);
            }

            $existing = HostelAttendance::withoutGlobalScopes()
                ->where('college_id', $college->id)
                ->whereNull('deleted_at')
                ->whereDate('attendance_date', $date)
                ->whereIn('student_enrollment_id', $enrollmentIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('student_enrollment_id');

            if ($existing->isNotEmpty() && ! $actor->hasPermission('hostel_attendance.update', $college->id)) {
                abort(403, 'Correcting existing hostel attendance requires the hostel_attendance.update permission.');
            }

            $markedAt = now();
            $counts = ['created' => 0, 'updated' => 0];

            foreach ($prepared as $row) {
                /** @var HostelAllocation $allocation */
                $allocation = $row['allocation'];
                /** @var StudentEnrollment $enrollment */
                $enrollment = $row['enrollment'];
                /** @var HostelAttendance|null $current */
                $current = $existing->get($enrollment->id);

                if ($current) {
                    $old = $this->snapshot($current);
                    $current->update([
                        'hostel_allocation_id' => $allocation->id,
                        'attendance_status' => $row['status'],
                        'remarks' => $row['remarks'],
                        'marked_at' => $markedAt,
                        'marked_by' => $actor->getKey(),
                        'updated_by' => $actor->getKey(),
                    ]);
                    $fresh = $current->refresh();
                    $this->audit->record('hostel_attendance.updated', $fresh, $old, $this->snapshot($fresh));
                    $counts['updated']++;

                    continue;
                }

                try {
                    $created = HostelAttendance::create([
                        'college_id' => $college->id,
                        'student_enrollment_id' => $enrollment->id,
                        'hostel_allocation_id' => $allocation->id,
                        'attendance_date' => $date,
                        'attendance_status' => $row['status'],
                        'remarks' => $row['remarks'],
                        'marked_at' => $markedAt,
                        'marked_by' => $actor->getKey(),
                        'created_by' => $actor->getKey(),
                        'updated_by' => $actor->getKey(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages([
                        'attendance_date' => 'Attendance for this student and date already exists. Correct the existing record instead.',
                    ]);
                }

                $this->audit->record('hostel_attendance.created', $created, [], $this->snapshot($created));
                $counts['created']++;
            }

            $this->audit->record('hostel_attendance.bulk_marked', null, [], [
                'attendance_date' => $date,
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'hostel_allocation_ids' => $allocationIds->all(),
            ]);

            return $counts;
        });
    }

    private function assertActingCollege(College $college): void
    {
        abort_unless((int) app(TenantContext::class)->id() === (int) $college->id, 403);
    }

    private function assertRecordTenant(HostelAttendance $attendance): void
    {
        $collegeId = app(TenantContext::class)->id();
        abort_unless($collegeId !== null && (int) $attendance->college_id === (int) $collegeId, 404);
    }

    private function lockCollege(College $college): void
    {
        College::query()->whereKey($college->id)->lockForUpdate()->firstOrFail();
    }

    private function lockCollegeId(int $collegeId): void
    {
        College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    }

    private function lockEnrollment(int $collegeId, int $enrollmentId): void
    {
        StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereKey($enrollmentId)
            ->lockForUpdate()
            ->first();
    }

    private function requireAllocation(int $collegeId, int $id, string $field): HostelAllocation
    {
        $allocation = HostelAllocation::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($id);

        if ($allocation === null) {
            throw ValidationException::withMessages([
                $field => 'Select a hostel allocation belonging to the active college.',
            ]);
        }

        return $allocation;
    }

    private function requireEnrollment(int $collegeId, int $id, string $field): StudentEnrollment
    {
        $enrollment = StudentEnrollment::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($id);

        if ($enrollment === null) {
            throw ValidationException::withMessages([
                $field => 'The allocation\'s student enrollment does not belong to the active college.',
            ]);
        }

        return $enrollment;
    }

    private function assertHierarchy(int $collegeId, HostelAllocation $allocation, string $field): void
    {
        $checks = [
            [Hostel::class, (int) $allocation->hostel_id, 'hostel'],
            [HostelBuilding::class, (int) $allocation->hostel_building_id, 'building'],
            [HostelRoom::class, (int) $allocation->hostel_room_id, 'room'],
            [HostelBed::class, (int) $allocation->hostel_bed_id, 'bed'],
            [AcademicYear::class, (int) $allocation->academic_year_id, 'academic year'],
        ];

        foreach ($checks as [$class, $id, $label]) {
            $exists = $class::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($id)
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    $field => "The allocation's {$label} does not belong to the active college.",
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $ids
     */
    private function assertRelatedSet(string $class, int $collegeId, Collection $ids, string $label): void
    {
        $wanted = $ids->map(fn ($id) => (int) $id)->unique()->filter()->values();
        if ($wanted->isEmpty()) {
            return;
        }

        $found = $class::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->whereIn('id', $wanted)
            ->pluck('id');

        if ($found->count() !== $wanted->count()) {
            throw ValidationException::withMessages([
                'records' => "A selected allocation's {$label} does not belong to the active college.",
            ]);
        }
    }

    /**
     * @return Collection<int, StudentEnrollment>
     */
    private function loadRelated(string $class, int $collegeId, Collection $ids): Collection
    {
        $wanted = $ids->map(fn ($id) => (int) $id)->unique()->filter()->values();

        return $class::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->whereIn('id', $wanted)
            ->get()
            ->keyBy('id');
    }

    private function assertCurrentResident(HostelAllocation $allocation, StudentEnrollment $enrollment, string $date, string $field): void
    {
        if ($allocation->status !== HostelAllocation::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                $field => 'Only current hostel residents can receive attendance.',
            ]);
        }

        if (in_array($enrollment->status, self::INVALID_ENROLLMENT_STATUSES, true)) {
            throw ValidationException::withMessages([
                $field => 'Attendance can only be marked for a valid student enrollment.',
            ]);
        }

        $this->assertDateWithinResidency($allocation, $date, $field);
    }

    private function assertDateWithinResidency(HostelAllocation $allocation, string $date, string $field): void
    {
        $allocationDate = $allocation->allocation_date?->toDateString() ?? (string) $allocation->allocation_date;
        if ($date < $allocationDate) {
            throw ValidationException::withMessages([
                $field => 'Attendance cannot be marked before the resident was allocated.',
            ]);
        }

        $vacated = $allocation->vacated_date?->toDateString();
        if ($vacated !== null && $date > $vacated) {
            throw ValidationException::withMessages([
                $field => 'Attendance cannot be marked after the resident vacated.',
            ]);
        }
    }

    private function assertEnrollmentMatches(HostelAllocation $allocation, mixed $postedEnrollmentId): void
    {
        if ($postedEnrollmentId === null || $postedEnrollmentId === '') {
            return;
        }

        if ((int) $postedEnrollmentId !== (int) $allocation->student_enrollment_id) {
            throw ValidationException::withMessages([
                'student_enrollment_id' => 'The student enrollment does not match the hostel allocation.',
            ]);
        }
    }

    private function assertNoDuplicate(int $collegeId, int $enrollmentId, string $date, ?int $ignoreId = null): void
    {
        $exists = HostelAttendance::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('student_enrollment_id', $enrollmentId)
            ->whereDate('attendance_date', $date)
            ->whereNull('deleted_at')
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Attendance for this student and date already exists. Correct the existing record instead.',
            ]);
        }
    }

    private function assertStatus(string $status, string $field): string
    {
        if (! in_array($status, HostelAttendance::STATUSES, true)) {
            throw ValidationException::withMessages([
                $field => 'Attendance status must be present, absent or leave.',
            ]);
        }

        return $status;
    }

    private function assertNotFuture(string $date, string $field): void
    {
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages([
                $field => 'Attendance cannot be marked for a future date.',
            ]);
        }
    }

    private function normalizeDate(string $date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(HostelAttendance $attendance): array
    {
        return [
            'student_enrollment_id' => $attendance->student_enrollment_id,
            'hostel_allocation_id' => $attendance->hostel_allocation_id,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'attendance_status' => $attendance->attendance_status,
            'remarks' => $attendance->remarks,
            'marked_by' => $attendance->marked_by,
        ];
    }
}
