<?php

namespace Tests\Feature\Hostel;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\AuditLog;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hostel Management Phase 2 — Hostel Allocation.
 *
 * Covers CRUD, tenant isolation, hierarchy validation, cross-tenant rejection,
 * duplicate active prevention, vacating, cancelling, date validation,
 * transactional behavior, RBAC and audit.
 */
class HostelAllocationsTest extends TestCase
{
    use HostelTestHelpers;

    private function year(College $college, string $suffix = ''): AcademicYear
    {
        return AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Year '.($suffix ?: Str::upper(Str::random(4))),
            'code' => Str::upper(Str::random(6)),
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
    }

    private function enrollment(College $college, AcademicYear $year, string $suffix = 'A'): StudentEnrollment
    {
        $student = Student::create([
            'college_id' => $college->id,
            'student_number' => 'STU-'.$suffix.Str::upper(Str::random(2)),
            'first_name' => 'Test',
            'last_name' => 'Student '.$suffix,
            'status' => 'active',
        ]);
        return StudentEnrollment::create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-'.$suffix.Str::upper(Str::random(2)),
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    public function test_allocation_can_be_created_and_bed_becomes_occupied(): void
    {
        $college = $this->makeCollege('HALL01');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);

        $this->asCollege($college, $user)
            ->post(route('hostel-allocations.store'), [
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $year->id,
                'hostel_id' => $bed->hostel_id,
                'hostel_building_id' => $bed->building_id,
                'hostel_room_id' => $bed->room_id,
                'hostel_bed_id' => $bed->id,
                'allocation_date' => now()->format('Y-m-d'),
                'remarks' => 'First allocation',
            ])
            ->assertRedirect(route('hostel-allocations.index'));

        $allocation = $this->withTenant($college, fn () => HostelAllocation::firstOrFail());
        $this->assertEquals($college->id, $allocation->college_id);
        $this->assertEquals($enrollment->id, $allocation->student_enrollment_id);
        $this->assertEquals(HostelAllocation::STATUS_ACTIVE, $allocation->status);

        $bed->refresh();
        $this->assertEquals(HostelBed::STATUS_OCCUPIED, $bed->status, 'Bed must become occupied after active allocation.');

        $this->asCollege($college, $user)->get(route('hostel-allocations.index'))->assertOk()->assertSee($enrollment->enrollment_number);
    }

    public function test_hierarchy_validation_building_must_belong_to_hostel(): void
    {
        $college = $this->makeCollege('HALL02');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $hostelA = $this->makeHostel($college);
        $hostelB = $this->makeHostel($college);
        $buildingB = $this->makeHostelBuilding($college, $hostelB);
        $roomB = $this->makeHostelRoom($college, $buildingB);
        $bedB = $this->makeHostelBed($college, $roomB);

        // Building belongs to hostelB, but we claim hostelA
        $this->asCollege($college, $user)
            ->post(route('hostel-allocations.store'), [
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $year->id,
                'hostel_id' => $hostelA->id,
                'hostel_building_id' => $buildingB->id,
                'hostel_room_id' => $roomB->id,
                'hostel_bed_id' => $bedB->id,
                'allocation_date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('hostel_building_id');

        $this->assertSame(0, $this->withTenant($college, fn () => HostelAllocation::count()));
    }

    public function test_room_must_belong_to_building_and_hostel(): void
    {
        $college = $this->makeCollege('HALL03');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $hostel = $this->makeHostel($college);
        $buildingA = $this->makeHostelBuilding($college, $hostel);
        $buildingB = $this->makeHostelBuilding($college, $hostel);
        $roomB = $this->makeHostelRoom($college, $buildingB);
        $bedB = $this->makeHostelBed($college, $roomB);

        $this->asCollege($college, $user)
            ->post(route('hostel-allocations.store'), [
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $year->id,
                'hostel_id' => $hostel->id,
                'hostel_building_id' => $buildingA->id,
                'hostel_room_id' => $roomB->id,
                'hostel_bed_id' => $bedB->id,
                'allocation_date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('hostel_room_id');
    }

    public function test_bed_must_belong_to_room_building_hostel(): void
    {
        $college = $this->makeCollege('HALL04');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $hostel = $this->makeHostel($college);
        $building = $this->makeHostelBuilding($college, $hostel);
        $roomA = $this->makeHostelRoom($college, $building);
        $roomB = $this->makeHostelRoom($college, $building);
        $bedB = $this->makeHostelBed($college, $roomB);

        $this->asCollege($college, $user)
            ->post(route('hostel-allocations.store'), [
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $year->id,
                'hostel_id' => $hostel->id,
                'hostel_building_id' => $building->id,
                'hostel_room_id' => $roomA->id,
                'hostel_bed_id' => $bedB->id,
                'allocation_date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('hostel_bed_id');
    }

    public function test_cross_tenant_combinations_are_rejected(): void
    {
        $collegeA = $this->makeCollege('HALL05A');
        $collegeB = $this->makeCollege('HALL05B');
        $user = $this->makeUserWithPermissions($collegeA, ['hostel_allocations.view', 'hostel_allocations.create']);
        $yearB = $this->year($collegeB);
        $enrollmentB = $this->enrollment($collegeB, $yearB);
        $bedA = $this->makeHostelBed($collegeA);

        // College B student with College A hostel
        $this->asCollege($collegeA, $user)
            ->post(route('hostel-allocations.store'), [
                'student_enrollment_id' => $enrollmentB->id,
                'academic_year_id' => $yearB->id,
                'hostel_id' => $bedA->hostel_id,
                'hostel_building_id' => $bedA->building_id,
                'hostel_room_id' => $bedA->room_id,
                'hostel_bed_id' => $bedA->id,
                'allocation_date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('student_enrollment_id');

        $this->assertSame(0, $this->withTenant($collegeA, fn () => HostelAllocation::count()));
    }

    public function test_duplicate_active_bed_allocation_is_prevented(): void
    {
        $college = $this->makeCollege('HALL06');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollmentA = $this->enrollment($college, $year, 'A');
        $enrollmentB = $this->enrollment($college, $year, 'B');
        $bed = $this->makeHostelBed($college);

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollmentA->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollmentB->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors('hostel_bed_id');

        $this->assertSame(1, $this->withTenant($college, fn () => HostelAllocation::count()));
    }

    public function test_duplicate_active_student_allocation_per_year_is_prevented(): void
    {
        $college = $this->makeCollege('HALL07');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bedA = $this->makeHostelBed($college);
        $bedB = $this->makeHostelBed($college);

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bedA->hostel_id,
            'hostel_building_id' => $bedA->building_id,
            'hostel_room_id' => $bedA->room_id,
            'hostel_bed_id' => $bedA->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bedB->hostel_id,
            'hostel_building_id' => $bedB->building_id,
            'hostel_room_id' => $bedB->room_id,
            'hostel_bed_id' => $bedB->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors('student_enrollment_id');
    }

    public function test_vacating_releases_bed_and_allows_reallocation(): void
    {
        $college = $this->makeCollege('HALL08');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update']);
        $year = $this->year($college);
        $enrollmentA = $this->enrollment($college, $year, 'A');
        $enrollmentB = $this->enrollment($college, $year, 'B');
        $bed = $this->makeHostelBed($college);

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollmentA->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->subDays(5)->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $allocation = $this->withTenant($college, fn () => HostelAllocation::firstOrFail());

        $this->asCollege($college, $user)->post(route('hostel-allocations.vacate', $allocation), [
            'vacated_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $allocation->refresh();
        $this->assertEquals(HostelAllocation::STATUS_VACATED, $allocation->status);
        $this->assertNotNull($allocation->vacated_date);

        $bed->refresh();
        $this->assertEquals(HostelBed::STATUS_AVAILABLE, $bed->status, 'Vacating must release bed.');

        // Now reallocation to same bed should succeed
        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollmentB->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelAllocation::count()));
    }

    public function test_cancelled_allocation_does_not_occupy_bed(): void
    {
        $college = $this->makeCollege('HALL09');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $allocation = $this->withTenant($college, fn () => HostelAllocation::firstOrFail());

        $this->asCollege($college, $user)->post(route('hostel-allocations.cancel', $allocation))->assertRedirect();

        $bed->refresh();
        $this->assertEquals(HostelBed::STATUS_AVAILABLE, $bed->status, 'Cancelled allocation must not occupy bed.');
    }

    public function test_historical_allocations_remain_intact(): void
    {
        $college = $this->makeCollege('HALL10');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->subDays(10)->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $first = $this->withTenant($college, fn () => HostelAllocation::firstOrFail());

        $this->asCollege($college, $user)->post(route('hostel-allocations.vacate', $first), [
            'vacated_date' => now()->subDays(5)->format('Y-m-d'),
        ])->assertRedirect();

        $enrollmentB = $this->enrollment($college, $year, 'B');
        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollmentB->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->subDays(2)->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $all = $this->withTenant($college, fn () => HostelAllocation::orderBy('id')->get());
        $this->assertCount(2, $all);
        $this->assertEquals(HostelAllocation::STATUS_VACATED, $all[0]->status);
        $this->assertEquals(HostelAllocation::STATUS_ACTIVE, $all[1]->status);
    }

    public function test_date_validation(): void
    {
        $college = $this->makeCollege('HALL11');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);

        // vacated_date before allocation_date
        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => '2026-09-10',
            'vacated_date' => '2026-09-05',
            'status' => 'vacated',
        ])->assertSessionHasErrors('vacated_date');

        // active with vacated_date
        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => '2026-09-10',
            'vacated_date' => '2026-09-12',
            'status' => 'active',
        ])->assertSessionHasErrors('vacated_date');
    }

    public function test_database_partial_unique_blocks_racing_active_duplicates(): void
    {
        $college = $this->makeCollege('HALL12');
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);

        $this->makeHostelAllocation($college, $enrollment, $bed, [
            'academic_year_id' => $year->id,
            'status' => HostelAllocation::STATUS_ACTIVE,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempt to bypass service and insert duplicate active allocation for same bed
        \App\Models\HostelAllocation::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $this->enrollment($college, $year, 'X')->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->format('Y-m-d'),
            'status' => HostelAllocation::STATUS_ACTIVE,
        ]);
    }

    public function test_allocations_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('HALL13');
        $other = $this->makeCollege('HALL13X');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.update', 'hostel_allocations.delete']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);
        $mine = $this->makeHostelAllocation($college, $enrollment, $bed);

        $otherYear = $this->year($other);
        $otherEnrollment = $this->enrollment($other, $otherYear);
        $otherBed = $this->makeHostelBed($other);
        $theirs = $this->makeHostelAllocation($other, $otherEnrollment, $otherBed);

        $this->asCollege($college, $user)
            ->get(route('hostel-allocations.index'))
            ->assertOk()
            ->assertSee($enrollment->enrollment_number)
            ->assertDontSee($otherEnrollment->enrollment_number);

        $this->asCollege($college, $user)->get(route('hostel-allocations.show', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->get(route('hostel-allocations.edit', $theirs))->assertNotFound();
    }

    public function test_rbac(): void
    {
        $college = $this->makeCollege('HALL14');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_allocations.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);
        $allocation = $this->makeHostelAllocation($college, $enrollment, $bed);

        $this->asCollege($college, $nobody)->get(route('hostel-allocations.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-allocations.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-allocations.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostel-allocations.store'), [])->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-allocations.edit', $allocation))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('hostel-allocations.update', $allocation), [])->assertForbidden();
    }

    public function test_audit(): void
    {
        $college = $this->makeCollege('HALL15');
        $user = $this->makeUserWithPermissions($college, ['hostel_allocations.view', 'hostel_allocations.create', 'hostel_allocations.update']);
        $year = $this->year($college);
        $enrollment = $this->enrollment($college, $year);
        $bed = $this->makeHostelBed($college);

        $this->asCollege($college, $user)->post(route('hostel-allocations.store'), [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $bed->hostel_id,
            'hostel_building_id' => $bed->building_id,
            'hostel_room_id' => $bed->room_id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $allocation = $this->withTenant($college, fn () => HostelAllocation::firstOrFail());

        $this->asCollege($college, $user)->put(route('hostel-allocations.update', $allocation), [
            'remarks' => 'Updated remarks',
        ])->assertRedirect();

        $this->asCollege($college, $user)->post(route('hostel-allocations.vacate', $allocation), [
            'vacated_date' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $actions = AuditLog::query()->where('subject_type', HostelAllocation::class)->where('subject_id', $allocation->id)->orderBy('id')->pluck('action')->all();

        $this->assertContains('hostel_allocations.created', $actions);
        $this->assertContains('hostel_allocations.vacated', $actions);
    }
}
