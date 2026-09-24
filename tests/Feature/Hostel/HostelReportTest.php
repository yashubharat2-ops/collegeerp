<?php

namespace Tests\Feature\Hostel;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Hostel\Services\HostelFeeService;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\HostelFeeAssignment;
use App\Models\StudentAcademicRecord;
use App\Models\StudentEnrollment;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hostel Management Phase 3 — read-only Hostel Reports.
 */
class HostelReportTest extends TestCase
{
    use HostelTestHelpers;

    public function test_reports_are_permission_gated_and_read_only(): void
    {
        $college = $this->makeCollege('HRT01');
        $stranger = $this->makeUserWithPermissions($college, ['hostel_attendance.view']);

        $this->asCollege($college, $stranger)->get(route('hostel-reports.index'))->assertForbidden();
        // No write route is registered, so POST/PUT/DELETE are method-not-allowed
        // for every caller, including someone without hostel_reports.view.
        $this->asCollege($college, $stranger)->post(route('hostel-reports.index'), [])->assertStatus(405);

        $viewer = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $this->asCollege($college, $viewer)->post(route('hostel-reports.index'), [])->assertStatus(405);
        $this->asCollege($college, $viewer)->put(route('hostel-reports.index'), [])->assertStatus(405);
        $this->asCollege($college, $viewer)->delete(route('hostel-reports.index'))->assertStatus(405);

        $before = [
            'attendance' => HostelAttendance::withoutGlobalScopes()->count(),
            'allocations' => HostelAllocation::withoutGlobalScopes()->count(),
            'payments' => FeePayment::withoutGlobalScopes()->count(),
        ];

        foreach (['occupancy', 'allocations', 'attendance', 'fees', 'bogus'] as $report) {
            $this->asCollege($college, $viewer)
                ->get(route('hostel-reports.index', ['report' => $report]))
                ->assertOk();
        }

        $this->assertSame($before, [
            'attendance' => HostelAttendance::withoutGlobalScopes()->count(),
            'allocations' => HostelAllocation::withoutGlobalScopes()->count(),
            'payments' => FeePayment::withoutGlobalScopes()->count(),
        ]);
    }

    public function test_occupancy_uses_allocations_as_the_source_of_truth(): void
    {
        $college = $this->makeCollege('HRT02');
        $user = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $hostel = $this->makeHostel($college, ['name' => 'North', 'code' => 'NORTH']);
        $building = $this->makeHostelBuilding($college, $hostel, ['name' => 'Block A']);
        $room = $this->makeHostelRoom($college, $building, ['room_number' => '101', 'capacity' => 2]);
        $occupiedBed = $this->makeHostelBed($college, $room, ['bed_number' => '1']);
        $this->makeHostelBed($college, $room, ['bed_number' => '2']);
        $this->makeHostelAllocation($college, null, $occupiedBed);

        $emptyHostel = $this->makeHostel($college, ['name' => 'South', 'code' => 'SOUTH']);
        $emptyBuilding = $this->makeHostelBuilding($college, $emptyHostel);
        $emptyRoom = $this->makeHostelRoom($college, $emptyBuilding, ['capacity' => 1]);
        $this->makeHostelBed($college, $emptyRoom);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', ['report' => 'occupancy']))
            ->assertOk()
            ->assertSee('Occupancy Summary')
            ->assertSee('Hostel-wise occupancy')
            ->assertSee('Room occupancy')
            ->assertViewHas('occupancy', function (array $report) use ($hostel, $building, $room) {
                $summary = $report['summary'];
                if ($summary['beds'] !== 3 || $summary['occupied'] !== 1 || $summary['vacant'] !== 2) {
                    return false;
                }
                if (abs(($summary['occupancy_percentage'] ?? 0) - 33.33) > 0.001) {
                    return false;
                }

                $north = collect($report['hostels'])->first(fn ($row) => $row['hostel']->id === $hostel->id);
                $block = collect($report['buildings'])->first(fn ($row) => $row['building']->id === $building->id);
                $roomRow = collect($report['rooms'])->first(fn ($row) => $row['room']->id === $room->id);

                return $north['occupied'] === 1
                    && $north['beds'] === 2
                    && $north['occupancy_percentage'] === 50.0
                    && $block['occupied'] === 1
                    && $roomRow['occupied'] === 1
                    && $roomRow['capacity'] === 2
                    && $roomRow['vacant'] === 1;
            });

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', ['report' => 'occupancy', 'hostel_id' => $emptyHostel->id]))
            ->assertViewHas('occupancy', fn (array $report) => $report['summary']['beds'] === 1
                && $report['summary']['occupied'] === 0
                && $report['summary']['occupancy_percentage'] === 0.0
                && count($report['hostels']) === 1);
    }

    public function test_allocation_summary_counts_statuses_and_lists_active_rows(): void
    {
        $college = $this->makeCollege('HRT03');
        $user = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $active = $this->makeHostelAllocation($college, null, null, ['allocation_date' => '2026-09-20']);
        $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_VACATED,
            'allocation_date' => '2026-09-06',
            'vacated_date' => '2026-09-10',
        ]);
        $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_CANCELLED,
            'allocation_date' => '2026-10-01',
        ]);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', ['report' => 'allocations']))
            ->assertOk()
            ->assertSee('Active Allocation Summary')
            ->assertViewHas('allocationReport', function (array $report) use ($active) {
                return $report['counts'] === ['active' => 1, 'vacated' => 1, 'cancelled' => 1, 'total' => 3]
                    && $report['rows']->count() === 1
                    && $report['rows']->first()->id === $active->id;
            });

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', ['report' => 'allocations', 'from' => '2026-09-05', 'to' => '2026-09-08']))
            ->assertViewHas('allocationReport', fn (array $report) => $report['counts']['active'] === 0
                && $report['counts']['vacated'] === 1
                && $report['rows']->count() === 0);
    }

    public function test_attendance_summary_counts_present_absent_leave_and_percentage(): void
    {
        $college = $this->makeCollege('HRT04');
        $user = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $hostel = $this->makeHostel($college, ['name' => 'Marked Hostel', 'code' => 'MRK']);
        $building = $this->makeHostelBuilding($college, $hostel);
        $room = $this->makeHostelRoom($college, $building);

        $marks = ['present', 'present', 'absent', 'leave'];
        foreach ($marks as $index => $status) {
            $bed = $this->makeHostelBed($college, $room, ['bed_number' => (string) ($index + 1)]);
            $allocation = $this->makeHostelAllocation($college, null, $bed, ['allocation_date' => '2026-09-01']);
            HostelAttendance::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'student_enrollment_id' => $allocation->student_enrollment_id,
                'hostel_allocation_id' => $allocation->id,
                'attendance_date' => '2026-09-20',
                'attendance_status' => $status,
                'marked_at' => now(),
            ]);
        }

        $outside = $this->makeHostelAllocation($college, null, null, ['allocation_date' => '2026-09-01']);
        HostelAttendance::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_enrollment_id' => $outside->student_enrollment_id,
            'hostel_allocation_id' => $outside->id,
            'attendance_date' => '2026-08-01',
            'attendance_status' => 'absent',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'attendance',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'hostel_id' => $hostel->id,
            ]))
            ->assertOk()
            ->assertSee('Attendance Summary')
            ->assertViewHas('attendanceReport', function (array $report) {
                $summary = $report['summary'];

                return $summary['present'] === 2
                    && $summary['absent'] === 1
                    && $summary['leave'] === 1
                    && $summary['total'] === 4
                    && $summary['attendance_percentage'] === 50.0
                    && count($report['hostels']) === 1
                    && $report['hostels'][0]['attendance_percentage'] === 50.0;
            });

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', ['report' => 'attendance', 'attendance_status' => 'leave']))
            ->assertViewHas('attendanceReport', fn (array $report) => $report['summary']['present'] === 2
                && $report['rows']->count() === 1
                && $report['rows']->first()->attendance_status === 'leave');

        $empty = $this->makeCollege('HRT04E');
        $emptyUser = $this->makeUserWithPermissions($empty, ['hostel_reports.view']);
        $this->asCollege($empty, $emptyUser)
            ->get(route('hostel-reports.index', ['report' => 'attendance']))
            ->assertViewHas('attendanceReport', fn (array $report) => $report['summary']['total'] === 0
                && $report['summary']['attendance_percentage'] === null);
    }

    public function test_fee_summary_matches_the_shared_ledger_including_refunds(): void
    {
        $college = $this->makeCollege('HRT05');
        $user = $this->makeUserWithPermissions($college, ['hostel_reports.view', 'hostel_fees.collect']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear, ['amount' => 10000]);
        $assignment = $this->makeHostelFeeAssignment($college, $allocation, $structure, ['assigned_amount' => 10000]);

        $this->withTenant($college, function () use ($assignment, $user) {
            app(FeeCollectionService::class)->collectHostelFee($assignment, [
                'payment_date' => now()->toDateString(),
                'payment_mode' => 'cash',
                'amount' => 4000,
            ], $user);
        });

        $payment = FeePayment::withoutGlobalScopes()->where('hostel_fee_assignment_id', $assignment->id)->firstOrFail();
        FeeRefund::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'fee_payment_id' => $payment->id,
            'refund_number' => 'RF-'.Str::upper(Str::random(6)),
            'refund_date' => now()->toDateString(),
            'amount' => 500,
            'status' => FeeRefund::STATUS_PROCESSED,
            'reason' => 'Partial return',
        ]);
        FeeRefund::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'fee_payment_id' => $payment->id,
            'refund_number' => 'RF-'.Str::upper(Str::random(6)),
            'refund_date' => now()->toDateString(),
            'amount' => 9000,
            'status' => FeeRefund::STATUS_REJECTED,
            'reason' => 'Rejected and must not affect the ledger',
        ]);
        FeePayment::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_enrollment_id' => $allocation->student_enrollment_id,
            'hostel_fee_assignment_id' => $assignment->id,
            'payment_number' => 'PAY-CANCEL-'.Str::upper(Str::random(4)),
            'payment_date' => now()->toDateString(),
            'payment_mode' => 'cash',
            'amount' => 2500,
            'status' => FeePayment::STATUS_CANCELLED,
        ]);

        $expected = $this->withTenant($college, fn () => app(HostelFeeService::class)->summaryFor($assignment->refresh()));

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', ['report' => 'fees']))
            ->assertOk()
            ->assertSee('Hostel Fee Summary')
            ->assertSee('Assigned')
            ->assertSee('Paid')
            ->assertSee('Outstanding')
            ->assertViewHas('feeReport', function (array $report) use ($expected, $assignment) {
                $totals = $report['totals'];
                $row = $report['rows']->first();

                return $totals['assignments'] === 1
                    && $totals['assigned'] === $expected['assigned']
                    && $totals['paid'] === $expected['paid']
                    && $totals['refunded'] === $expected['refunded']
                    && $totals['net_collected'] === $expected['net_collected']
                    && $totals['outstanding'] === $expected['outstanding']
                    && $expected['outstanding'] === 6500.0
                    && $row->id === $assignment->id
                    && $row->ledger['outstanding'] === $expected['outstanding'];
            });
    }

    public function test_filters_narrow_occupancy_attendance_allocations_and_fees(): void
    {
        $college = $this->makeCollege('HRT06');
        $user = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Year Filter',
            'code' => 'YF'.Str::upper(Str::random(4)),
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
        $otherYear = AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Other Year',
            'code' => 'OY'.Str::upper(Str::random(4)),
            'starts_on' => '2025-07-01',
            'ends_on' => '2026-06-30',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Term One',
            'code' => 'T1'.Str::upper(Str::random(3)),
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $sharedBed = $this->makeHostelBed($college);
        $secondBed = $this->makeHostelBed($college, $sharedBed->room);
        $inTerm = $this->makeHostelAllocation($college, null, $sharedBed, [
            'academic_year_id' => $year->id,
            'allocation_date' => '2026-09-01',
        ]);
        $outOfTerm = $this->makeHostelAllocation($college, null, $secondBed, [
            'academic_year_id' => $otherYear->id,
            'allocation_date' => '2026-09-01',
        ]);

        $enrollment = StudentEnrollment::withoutGlobalScopes()->findOrFail($inTerm->student_enrollment_id);
        StudentAcademicRecord::create([
            'college_id' => $college->id,
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'academic_status' => 'enrolled',
            'promotion_status' => 'not_applicable',
            'completion_status' => 'pending',
        ]);

        foreach ([$inTerm, $outOfTerm] as $allocation) {
            HostelAttendance::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'student_enrollment_id' => $allocation->student_enrollment_id,
                'hostel_allocation_id' => $allocation->id,
                'attendance_date' => '2026-09-15',
                'attendance_status' => 'present',
                'marked_at' => now(),
            ]);
        }

        $structure = $this->makeHostelFeeStructure($college, $year, ['amount' => 1000]);
        $this->makeHostelFeeAssignment($college, $inTerm, $structure, [
            'academic_year_id' => $year->id,
            'assigned_amount' => 1000,
            'effective_from' => '2026-09-01',
            'effective_until' => '2026-12-31',
        ]);
        $otherStructure = $this->makeHostelFeeStructure($college, $otherYear, ['amount' => 8000]);
        $this->makeHostelFeeAssignment($college, $outOfTerm, $otherStructure, [
            'academic_year_id' => $otherYear->id,
            'assigned_amount' => 8000,
            'effective_from' => '2025-09-01',
        ]);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'allocations',
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
            ]))
            ->assertViewHas('allocationReport', fn (array $report) => $report['counts']['active'] === 1
                && $report['rows']->first()->id === $inTerm->id);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'attendance',
                'academic_term_id' => $term->id,
                'hostel_id' => $inTerm->hostel_id,
                'hostel_building_id' => $inTerm->hostel_building_id,
            ]))
            ->assertViewHas('attendanceReport', fn (array $report) => $report['summary']['present'] === 1
                && $report['summary']['total'] === 1);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'occupancy',
                'academic_year_id' => $year->id,
                'from' => '2026-08-01',
                'to' => '2026-08-15',
            ]))
            ->assertViewHas('occupancy', fn (array $report) => $report['summary']['occupied'] === 0);

        $this->asCollege($college, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'fees',
                'academic_year_id' => $year->id,
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ]))
            ->assertViewHas('feeReport', fn (array $report) => $report['totals']['assignments'] === 1
                && (float) $report['totals']['assigned'] === 1000.0);
    }

    public function test_reports_do_not_leak_another_college(): void
    {
        $collegeA = $this->makeCollege('HRT07A');
        $collegeB = $this->makeCollege('HRT07B');
        $user = $this->makeUserWithPermissions($collegeA, ['hostel_reports.view']);

        $foreignAllocation = $this->makeHostelAllocation($collegeB);
        HostelAttendance::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'student_enrollment_id' => $foreignAllocation->student_enrollment_id,
            'hostel_allocation_id' => $foreignAllocation->id,
            'attendance_date' => now()->toDateString(),
            'attendance_status' => 'absent',
            'marked_at' => now(),
        ]);
        $year = AcademicYear::withoutGlobalScopes()->findOrFail($foreignAllocation->academic_year_id);
        $structure = $this->makeHostelFeeStructure($collegeB, $year, ['amount' => 9999]);
        $this->makeHostelFeeAssignment($collegeB, $foreignAllocation, $structure, ['assigned_amount' => 9999]);

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', ['report' => 'occupancy']))
            ->assertViewHas('occupancy', fn (array $report) => $report['summary']['occupied'] === 0 && $report['summary']['beds'] === 0);

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', ['report' => 'allocations']))
            ->assertViewHas('allocationReport', fn (array $report) => $report['counts']['total'] === 0);

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', ['report' => 'attendance']))
            ->assertViewHas('attendanceReport', fn (array $report) => $report['summary']['total'] === 0);

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', ['report' => 'fees', 'academic_year_id' => $year->id]))
            ->assertViewHas('feeReport', fn (array $report) => $report['totals']['assignments'] === 0
                && (float) $report['totals']['assigned'] === 0.0);
    }
}
