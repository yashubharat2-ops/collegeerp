<?php

namespace Tests\Feature\Hostel;

use App\Models\AuditLog;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Hostel Management Phase 3 — Hostel Attendance.
 */
class HostelAttendanceTest extends TestCase
{
    use HostelTestHelpers;

    private const WRITE = [
        'hostel_attendance.view',
        'hostel_attendance.create',
        'hostel_attendance.update',
        'hostel_attendance.delete',
    ];

    public function test_create_records_attendance_for_a_current_resident_and_stamps_server_fields(): void
    {
        $college = $this->makeCollege('HAT01');
        $other = $this->makeCollege('HAT01B');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $allocation = $this->makeHostelAllocation($college);
        $date = now()->toDateString();

        $this->asCollege($college, $user)
            ->post(route('hostel-attendance.store'), [
                'hostel_allocation_id' => $allocation->id,
                'college_id' => $other->id,
                'marked_by' => 999999,
                'created_by' => 999999,
                'attendance_date' => $date,
                'attendance_status' => 'present',
                'remarks' => 'Night check',
            ])
            ->assertRedirect(route('hostel-attendance.index'))
            ->assertSessionHasNoErrors();

        $attendance = $this->withTenant($college, fn () => HostelAttendance::query()->firstOrFail());
        $this->assertSame($college->id, $attendance->college_id);
        $this->assertNotSame($other->id, $attendance->college_id);
        $this->assertSame($allocation->student_enrollment_id, $attendance->student_enrollment_id);
        $this->assertSame($allocation->id, $attendance->hostel_allocation_id);
        $this->assertSame('present', $attendance->attendance_status);
        $this->assertSame($user->id, $attendance->marked_by);
        $this->assertSame($user->id, $attendance->created_by);
        $this->assertNotNull($attendance->marked_at);
        $this->assertSame('Night check', $attendance->remarks);

        $enrollmentNumber = StudentEnrollment::withoutGlobalScopes()->findOrFail($allocation->student_enrollment_id)->enrollment_number;

        $this->asCollege($college, $user)
            ->get(route('hostel-attendance.index', ['search' => $enrollmentNumber]))
            ->assertOk()
            ->assertSee('Night check')
            ->assertSee('Present');
    }

    public function test_duplicate_attendance_for_the_same_student_and_date_is_rejected(): void
    {
        $college = $this->makeCollege('HAT02');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $allocation = $this->makeHostelAllocation($college);
        $payload = [
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'present',
        ];

        $this->asCollege($college, $user)->post(route('hostel-attendance.store'), $payload)->assertSessionHasNoErrors();
        $this->asCollege($college, $user)->post(route('hostel-attendance.store'), $payload + ['attendance_status' => 'absent'])
            ->assertSessionHasErrors('attendance_date');

        $this->assertSame(1, $this->withTenant($college, fn () => HostelAttendance::query()->count()));
    }

    public function test_mismatched_enrollment_is_rejected_and_not_stored(): void
    {
        $college = $this->makeCollege('HAT02B');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $allocation = $this->makeHostelAllocation($college);
        $other = $this->makeHostelAllocation($college);

        $this->asCollege($college, $user)->post(route('hostel-attendance.store'), [
            'hostel_allocation_id' => $allocation->id,
            'student_enrollment_id' => $other->student_enrollment_id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'present',
        ])->assertSessionHasErrors('student_enrollment_id');

        $this->assertSame(0, HostelAttendance::withoutGlobalScopes()->count());
    }

    public function test_bulk_attendance_is_transactional_and_does_not_duplicate(): void
    {
        $college = $this->makeCollege('HAT03');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $first = $this->makeHostelAllocation($college);
        $second = $this->makeHostelAllocation($college);
        $vacated = $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_VACATED,
            'vacated_date' => now()->toDateString(),
        ]);
        $date = now()->toDateString();

        $this->asCollege($college, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [
                ['hostel_allocation_id' => $first->id, 'attendance_status' => 'present'],
                ['hostel_allocation_id' => $vacated->id, 'attendance_status' => 'absent'],
            ],
        ])->assertSessionHasErrors('records.1.hostel_allocation_id');

        $this->assertSame(0, HostelAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count(), 'A failed bulk mark must not persist the valid row.');

        $this->asCollege($college, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [
                ['hostel_allocation_id' => $first->id, 'attendance_status' => 'present', 'remarks' => 'In'],
                ['hostel_allocation_id' => $second->id, 'attendance_status' => 'leave'],
                ['hostel_allocation_id' => $first->id, 'attendance_status' => 'absent'],
            ],
        ])->assertSessionHasErrors('records.2.hostel_allocation_id');
        $this->assertSame(0, HostelAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());

        $this->asCollege($college, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [
                ['hostel_allocation_id' => $first->id, 'attendance_status' => 'present'],
                ['hostel_allocation_id' => $second->id, 'attendance_status' => 'absent'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelAttendance::query()->count()));

        $this->asCollege($college, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [
                ['hostel_allocation_id' => $first->id, 'attendance_status' => 'leave', 'remarks' => 'Corrected'],
                ['hostel_allocation_id' => $second->id, 'attendance_status' => 'present'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelAttendance::query()->count()), 'Re-saving the roll call must correct, not duplicate.');
        $corrected = $this->withTenant($college, fn () => HostelAttendance::query()->where('hostel_allocation_id', $first->id)->firstOrFail());
        $this->assertSame('leave', $corrected->attendance_status);
        $this->assertSame('Corrected', $corrected->remarks);
    }

    public function test_status_must_be_present_absent_or_leave(): void
    {
        $college = $this->makeCollege('HAT04');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $allocation = $this->makeHostelAllocation($college);

        $this->asCollege($college, $user)->post(route('hostel-attendance.store'), [
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'late',
        ])->assertSessionHasErrors('attendance_status');

        $this->asCollege($college, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => now()->toDateString(),
            'records' => [
                ['hostel_allocation_id' => $allocation->id, 'attendance_status' => 'holiday'],
            ],
        ])->assertSessionHasErrors('records.0.attendance_status');

        $this->assertSame(0, HostelAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_only_active_allocations_of_valid_enrollments_can_be_marked(): void
    {
        $college = $this->makeCollege('HAT05');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $date = now()->toDateString();

        $vacated = $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_VACATED,
            'vacated_date' => $date,
        ]);
        $cancelled = $this->makeHostelAllocation($college, null, null, ['status' => HostelAllocation::STATUS_CANCELLED]);
        $withdrawn = $this->makeHostelAllocation($college);
        StudentEnrollment::withoutGlobalScopes()->whereKey($withdrawn->student_enrollment_id)->update(['status' => 'withdrawn']);

        foreach ([$vacated, $cancelled, $withdrawn] as $allocation) {
            $this->asCollege($college, $user)->post(route('hostel-attendance.store'), [
                'hostel_allocation_id' => $allocation->id,
                'attendance_date' => $date,
                'attendance_status' => 'present',
            ])->assertSessionHasErrors('hostel_allocation_id');
        }

        $futureResident = $this->makeHostelAllocation($college, null, null, ['allocation_date' => now()->addDay()->toDateString()]);
        $this->asCollege($college, $user)->post(route('hostel-attendance.store'), [
            'hostel_allocation_id' => $futureResident->id,
            'attendance_date' => $date,
            'attendance_status' => 'present',
        ])->assertSessionHasErrors('hostel_allocation_id');

        $this->assertSame(0, HostelAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_cross_tenant_allocation_cannot_be_marked_or_corrected(): void
    {
        $collegeA = $this->makeCollege('HAT06A');
        $collegeB = $this->makeCollege('HAT06B');
        $user = $this->makeUserWithPermissions($collegeA, self::WRITE);
        $foreign = $this->makeHostelAllocation($collegeB);
        $local = $this->makeHostelAllocation($collegeA);

        $this->asCollege($collegeA, $user)->post(route('hostel-attendance.store'), [
            'hostel_allocation_id' => $foreign->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'present',
        ])->assertSessionHasErrors('hostel_allocation_id');

        $this->asCollege($collegeA, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => now()->toDateString(),
            'records' => [
                ['hostel_allocation_id' => $local->id, 'attendance_status' => 'present'],
                ['hostel_allocation_id' => $foreign->id, 'attendance_status' => 'absent'],
            ],
        ])->assertSessionHasErrors('records.1.hostel_allocation_id');

        $this->assertSame(0, HostelAttendance::withoutGlobalScopes()->count());

        $owned = HostelAttendance::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'student_enrollment_id' => $foreign->student_enrollment_id,
            'hostel_allocation_id' => $foreign->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'present',
            'marked_at' => now(),
            'marked_by' => $user->id,
        ]);

        $this->asCollege($collegeA, $user)->get(route('hostel-attendance.edit', $owned))->assertNotFound();
        $this->asCollege($collegeA, $user)->put(route('hostel-attendance.update', $owned), [
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'absent',
        ])->assertForbidden();
        $this->asCollege($collegeA, $user)->delete(route('hostel-attendance.destroy', $owned))->assertNotFound();

        $this->assertSame('present', HostelAttendance::withoutGlobalScopes()->findOrFail($owned->id)->attendance_status);
        $this->assertNull(HostelAttendance::withoutGlobalScopes()->findOrFail($owned->id)->deleted_at);
    }

    public function test_rbac_gates_view_create_update_delete_and_bulk(): void
    {
        $college = $this->makeCollege('HAT07');
        $allocation = $this->makeHostelAllocation($college);
        $date = now()->toDateString();
        $payload = [
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => $date,
            'attendance_status' => 'present',
        ];

        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $this->asCollege($college, $stranger)->get(route('hostel-attendance.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->post(route('hostel-attendance.store'), $payload)->assertForbidden();
        $this->asCollege($college, $stranger)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [$payload],
        ])->assertForbidden();

        $viewer = $this->makeUserWithPermissions($college, ['hostel_attendance.view']);
        $this->asCollege($college, $viewer)->get(route('hostel-attendance.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-attendance.bulk'))->assertOk();
        $this->asCollege($college, $viewer)->post(route('hostel-attendance.store'), $payload)->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-attendance.create'))->assertForbidden();

        $creator = $this->makeUserWithPermissions($college, ['hostel_attendance.view', 'hostel_attendance.create']);
        $this->asCollege($college, $creator)->post(route('hostel-attendance.store'), $payload)->assertSessionHasNoErrors();
        $attendance = $this->withTenant($college, fn () => HostelAttendance::query()->firstOrFail());

        $this->asCollege($college, $creator)->put(route('hostel-attendance.update', $attendance), [
            'attendance_date' => $date,
            'attendance_status' => 'absent',
        ])->assertForbidden();
        $this->asCollege($college, $creator)->delete(route('hostel-attendance.destroy', $attendance))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [['hostel_allocation_id' => $allocation->id, 'attendance_status' => 'leave']],
        ])->assertForbidden();
        $this->assertSame('present', $attendance->refresh()->attendance_status);

        $editor = $this->makeUserWithPermissions($college, ['hostel_attendance.view', 'hostel_attendance.update', 'hostel_attendance.delete']);
        $this->asCollege($college, $editor)->put(route('hostel-attendance.update', $attendance), [
            'attendance_date' => $date,
            'attendance_status' => 'leave',
            'remarks' => 'Corrected by warden',
        ])->assertRedirect(route('hostel-attendance.index'));
        $this->assertSame('leave', $attendance->refresh()->attendance_status);

        $this->asCollege($college, $editor)->delete(route('hostel-attendance.destroy', $attendance))->assertRedirect();
        $this->assertSame(0, $this->withTenant($college, fn () => HostelAttendance::query()->count()));
        $this->assertNotNull(HostelAttendance::withoutGlobalScopes()->find($attendance->id)->deleted_at);
    }

    public function test_mutations_are_audited(): void
    {
        $college = $this->makeCollege('HAT08');
        $user = $this->makeUserWithPermissions($college, self::WRITE);
        $allocation = $this->makeHostelAllocation($college);
        $date = now()->toDateString();

        $this->asCollege($college, $user)->post(route('hostel-attendance.store'), [
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => $date,
            'attendance_status' => 'present',
        ])->assertSessionHasNoErrors();

        $attendance = $this->withTenant($college, fn () => HostelAttendance::query()->firstOrFail());
        $created = AuditLog::query()->where('action', 'hostel_attendance.created')->where('subject_id', $attendance->id)->first();
        $this->assertNotNull($created);
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame('present', $created->new_values['attendance_status']);

        $this->asCollege($college, $user)->put(route('hostel-attendance.update', $attendance), [
            'attendance_date' => $date,
            'attendance_status' => 'absent',
            'remarks' => 'Left campus',
        ])->assertSessionHasNoErrors();

        $updated = AuditLog::query()->where('action', 'hostel_attendance.updated')->where('subject_id', $attendance->id)->first();
        $this->assertNotNull($updated);
        $this->assertSame('present', $updated->old_values['attendance_status']);
        $this->assertSame('absent', $updated->new_values['attendance_status']);

        $second = $this->makeHostelAllocation($college);
        $this->asCollege($college, $user)->post(route('hostel-attendance.bulk.store'), [
            'attendance_date' => $date,
            'records' => [
                ['hostel_allocation_id' => $second->id, 'attendance_status' => 'present'],
            ],
        ])->assertSessionHasNoErrors();

        $bulk = AuditLog::query()->where('action', 'hostel_attendance.bulk_marked')->where('college_id', $college->id)->first();
        $this->assertNotNull($bulk);
        $this->assertSame(1, $bulk->new_values['created']);
        $this->assertSame($date, $bulk->new_values['attendance_date']);

        $this->asCollege($college, $user)->delete(route('hostel-attendance.destroy', $attendance))->assertRedirect();
        $this->assertNotNull(AuditLog::query()->where('action', 'hostel_attendance.deleted')->where('subject_id', $attendance->id)->first());
    }

    public function test_index_filters_by_date_hostel_student_and_status_and_paginates(): void
    {
        $college = $this->makeCollege('HAT09');
        $user = $this->makeUserWithPermissions($college, ['hostel_attendance.view']);
        $allocation = $this->makeHostelAllocation($college, null, null, ['allocation_date' => now()->subDays(3)->toDateString()]);
        $otherHostel = $this->makeHostel($college, ['name' => 'Other Hostel', 'code' => 'OTH1']);
        $otherBuilding = $this->makeHostelBuilding($college, $otherHostel);
        $otherRoom = $this->makeHostelRoom($college, $otherBuilding);
        $otherBed = $this->makeHostelBed($college, $otherRoom);
        $other = $this->makeHostelAllocation($college, null, $otherBed, ['allocation_date' => now()->subDays(3)->toDateString()]);

        HostelAttendance::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_enrollment_id' => $allocation->student_enrollment_id,
            'hostel_allocation_id' => $allocation->id,
            'attendance_date' => now()->subDay()->toDateString(),
            'attendance_status' => 'absent',
            'marked_at' => now(),
        ]);
        HostelAttendance::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_enrollment_id' => $other->student_enrollment_id,
            'hostel_allocation_id' => $other->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $enrollment = StudentEnrollment::withoutGlobalScopes()->findOrFail($other->student_enrollment_id);
        $studentNumber = \App\Models\Student::withoutGlobalScopes()->findOrFail($enrollment->student_id)->student_number;

        $this->asCollege($college, $user)
            ->get(route('hostel-attendance.index', [
                'attendance_date' => now()->toDateString(),
                'hostel_id' => $otherHostel->id,
                'attendance_status' => 'present',
                'search' => $studentNumber,
            ]))
            ->assertOk()
            ->assertViewHas('attendances', fn ($page) => $page->count() === 1 && $page->first()->hostel_allocation_id === $other->id);

        $this->asCollege($college, $user)
            ->get(route('hostel-attendance.index'))
            ->assertOk()
            ->assertViewHas('attendances', fn ($page) => $page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator);
    }
}
