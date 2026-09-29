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

        foreach (['hostels', 'occupancy', 'allocations', 'attendance', 'fees', 'vacated', 'summary', 'bogus'] as $report) {
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

    public function test_report_catalog_has_exact_names_and_order(): void
    {
        $college = $this->makeCollege('HRT00');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $expected = [
            'hostels' => 'Hostel / Building Report',
            'occupancy' => 'Room / Bed Occupancy Report',
            'allocations' => 'Hostel Allocation Report',
            'attendance' => 'Hostel Attendance Report',
            'fees' => 'Hostel Fee Report',
            'vacated' => 'Vacated Student Report',
            'summary' => 'Hostel Summary',
        ];

        $response = $this->asCollege($college, $viewer)
            ->get(route('hostel-reports.index'))
            ->assertOk()
            ->assertViewHas('reports', fn (array $reports) => $reports === $expected);

        $html = $response->getContent();
        $cursor = -1;
        foreach ($expected as $label) {
            $position = strpos($html, $label);
            $this->assertNotFalse($position, "Missing Hostel Report tab: {$label}");
            $this->assertGreaterThan($cursor, $position, "Hostel Report tab is out of order: {$label}");
            $cursor = $position;
        }
    }

    public function test_hostel_and_building_report_uses_live_scoped_hierarchy_counts(): void
    {
        $college = $this->makeCollege('HRT08');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $hostel = $this->makeHostel($college, ['name' => 'North House', 'code' => 'NORTH']);
        $building = $this->makeHostelBuilding($college, $hostel, ['name' => 'Block A', 'code' => 'A']);
        $room = $this->makeHostelRoom($college, $building, ['room_number' => '101']);
        $this->makeHostelBed($college, $room, ['bed_number' => '1']);

        $this->asCollege($college, $viewer)
            ->get(route('hostel-reports.index', ['report' => 'hostels']))
            ->assertOk()
            ->assertSee('North House')
            ->assertSee('Block A')
            ->assertViewHas('hostels', fn ($rows) => $rows->total() === 1
                && $rows->first()->id === $hostel->id
                && $rows->first()->buildings_count === 1
                && $rows->first()->rooms_count === 1
                && $rows->first()->beds_count === 1)
            ->assertViewHas('buildings', fn ($rows) => $rows->total() === 1
                && $rows->first()->id === $building->id
                && $rows->first()->rooms_count === 1
                && $rows->first()->beds_count === 1);
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
            ->assertSee('Room occupancy')
            ->assertSee('Bed occupancy')
            ->assertViewHas('occupancy', function (array $report) use ($room) {
                $summary = $report['summary'];
                if ($summary['beds'] !== 3 || $summary['occupied'] !== 1 || $summary['available'] !== 2) {
                    return false;
                }
                if (abs(($summary['occupancy_percentage'] ?? 0) - 33.33) > 0.001) {
                    return false;
                }

                $roomRow = $report['rooms']->getCollection()->first(fn ($row) => $row->id === $room->id);

                return $roomRow !== null
                    && $roomRow->occupied_beds_count === 1
                    && $roomRow->total_beds_count === 2
                    && $roomRow->available_beds_count === 1
                    && $roomRow->capacity === 2
                    && $roomRow->occupancy_status === 'Partially occupied';
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

    public function test_vacated_student_report_uses_vacated_allocation_history_and_date_filters(): void
    {
        $college = $this->makeCollege('HRT09');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $matching = $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_VACATED,
            'allocation_date' => '2026-09-01',
            'vacated_date' => '2026-09-15',
            'remarks' => 'Moved out at term end',
        ]);
        $outsideRange = $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_VACATED,
            'allocation_date' => '2026-08-01',
            'vacated_date' => '2026-08-30',
        ]);
        $this->makeHostelAllocation($college, null, null, [
            'status' => HostelAllocation::STATUS_CANCELLED,
            'allocation_date' => '2026-09-10',
        ]);

        $this->asCollege($college, $viewer)
            ->get(route('hostel-reports.index', [
                'report' => 'vacated',
                'from' => '2026-09-10',
                'to' => '2026-09-20',
            ]))
            ->assertOk()
            ->assertSee('Moved out at term end')
            ->assertViewHas('vacatedReport', fn (array $report) => $report['count'] === 1
                && $report['rows']->total() === 1
                && $report['rows']->first()->id === $matching->id
                && $report['rows']->first()->id !== $outsideRange->id);
    }

    public function test_hostel_summary_reuses_live_dashboard_attendance_and_fee_sources(): void
    {
        $college = $this->makeCollege('HRT10');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_reports.view']);
        $hostel = $this->makeHostel($college, ['name' => 'Summary House', 'code' => 'SUM']);
        $building = $this->makeHostelBuilding($college, $hostel);
        $room = $this->makeHostelRoom($college, $building, ['capacity' => 2]);
        $activeBed = $this->makeHostelBed($college, $room, ['bed_number' => '1']);
        $vacatedBed = $this->makeHostelBed($college, $room, ['bed_number' => '2']);
        $active = $this->makeHostelAllocation($college, null, $activeBed, ['allocation_date' => '2026-09-01']);
        $vacated = $this->makeHostelAllocation($college, null, $vacatedBed, [
            'status' => HostelAllocation::STATUS_VACATED,
            'allocation_date' => '2026-08-01',
            'vacated_date' => '2026-09-10',
        ]);

        foreach ([[$active, 'present'], [$vacated, 'absent']] as [$allocation, $status]) {
            HostelAttendance::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'student_enrollment_id' => $allocation->student_enrollment_id,
                'hostel_allocation_id' => $allocation->id,
                'attendance_date' => '2026-09-15',
                'attendance_status' => $status,
                'marked_at' => now(),
            ]);
        }

        $structure = $this->makeHostelFeeStructure($college, $active->academicYear, ['amount' => 1500]);
        $this->makeHostelFeeAssignment($college, $active, $structure, ['assigned_amount' => 1500]);

        $this->asCollege($college, $viewer)
            ->get(route('hostel-reports.index', ['report' => 'summary']))
            ->assertOk()
            ->assertSee('Total hostels')
            ->assertViewHas('summary', function (array $summary): bool {
                return $summary['total_hostels'] === 1
                    && $summary['total_buildings'] === 1
                    && $summary['total_rooms'] === 1
                    && $summary['total_beds'] === 2
                    && $summary['occupied_beds'] === 1
                    && $summary['available_beds'] === 1
                    && $summary['active_allocations'] === 1
                    && $summary['vacated_students'] === 1
                    && $summary['attendance']['present'] === 1
                    && $summary['attendance']['absent'] === 1
                    && $summary['attendance']['total'] === 2
                    && $summary['attendance']['attendance_percentage'] === 50.0
                    && $summary['fees']['assignments'] === 1
                    && (float) $summary['fees']['assigned'] === 1500.0
                    && (float) $summary['fees']['outstanding'] === 1500.0;
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
        $foreignVacated = $this->makeHostelAllocation($collegeB, null, null, [
            'status' => HostelAllocation::STATUS_VACATED,
            'allocation_date' => '2026-08-01',
            'vacated_date' => '2026-09-10',
        ]);
        $foreignEnrollment = StudentEnrollment::withoutGlobalScopes()->findOrFail($foreignAllocation->student_enrollment_id);
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
            ->get(route('hostel-reports.index', [
                'report' => 'hostels',
                'hostel_id' => $foreignAllocation->hostel_id,
                'hostel_building_id' => $foreignAllocation->hostel_building_id,
            ]))
            ->assertViewHas('hostels', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('buildings', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('filterOptions', fn (array $options) => ! $options['hostels']->contains('id', $foreignAllocation->hostel_id)
                && ! $options['buildings']->contains('id', $foreignAllocation->hostel_building_id));

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'occupancy',
                'academic_year_id' => $year->id,
                'hostel_id' => $foreignAllocation->hostel_id,
                'hostel_building_id' => $foreignAllocation->hostel_building_id,
                'hostel_room_id' => $foreignAllocation->hostel_room_id,
                'hostel_bed_id' => $foreignAllocation->hostel_bed_id,
            ]))
            ->assertViewHas('occupancy', fn (array $report) => $report['summary']['occupied'] === 0 && $report['summary']['beds'] === 0)
            ->assertViewHas('rooms', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('beds', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('filterOptions', fn (array $options) => ! $options['hostels']->contains('id', $foreignAllocation->hostel_id)
                && ! $options['buildings']->contains('id', $foreignAllocation->hostel_building_id)
                && ! $options['rooms']->contains('id', $foreignAllocation->hostel_room_id)
                && ! $options['beds']->contains('id', $foreignAllocation->hostel_bed_id)
                && ! $options['years']->contains('id', $year->id));

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'allocations',
                'academic_year_id' => $year->id,
                'hostel_id' => $foreignAllocation->hostel_id,
                'hostel_building_id' => $foreignAllocation->hostel_building_id,
                'hostel_room_id' => $foreignAllocation->hostel_room_id,
                'hostel_bed_id' => $foreignAllocation->hostel_bed_id,
                'student_id' => $foreignEnrollment->student_id,
            ]))
            ->assertViewHas('allocationReport', fn (array $report) => $report['counts']['total'] === 0)
            ->assertViewHas('filterOptions', fn (array $options) => ! $options['students']->contains('id', $foreignEnrollment->student_id));

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'attendance',
                'academic_year_id' => $year->id,
                'hostel_id' => $foreignAllocation->hostel_id,
                'hostel_building_id' => $foreignAllocation->hostel_building_id,
                'hostel_room_id' => $foreignAllocation->hostel_room_id,
                'hostel_bed_id' => $foreignAllocation->hostel_bed_id,
                'student_id' => $foreignEnrollment->student_id,
            ]))
            ->assertViewHas('attendanceReport', fn (array $report) => $report['summary']['total'] === 0);

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'fees',
                'academic_year_id' => $year->id,
                'hostel_id' => $foreignAllocation->hostel_id,
                'hostel_building_id' => $foreignAllocation->hostel_building_id,
                'hostel_room_id' => $foreignAllocation->hostel_room_id,
                'hostel_bed_id' => $foreignAllocation->hostel_bed_id,
                'student_id' => $foreignEnrollment->student_id,
            ]))
            ->assertViewHas('feeReport', fn (array $report) => $report['totals']['assignments'] === 0
                && (float) $report['totals']['assigned'] === 0.0)
            ->assertViewHas('filterOptions', fn (array $options) => ! $options['students']->contains('id', $foreignEnrollment->student_id));

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', [
                'report' => 'vacated',
                'academic_year_id' => $foreignVacated->academic_year_id,
                'hostel_id' => $foreignVacated->hostel_id,
                'hostel_building_id' => $foreignVacated->hostel_building_id,
                'hostel_room_id' => $foreignVacated->hostel_room_id,
                'hostel_bed_id' => $foreignVacated->hostel_bed_id,
            ]))
            ->assertViewHas('vacatedReport', fn (array $report) => $report['count'] === 0
                && $report['rows']->total() === 0);

        $this->asCollege($collegeA, $user)
            ->get(route('hostel-reports.index', ['report' => 'summary']))
            ->assertViewHas('summary', fn (array $summary) => $summary['total_hostels'] === 0
                && $summary['active_allocations'] === 0
                && $summary['vacated_students'] === 0
                && $summary['attendance']['total'] === 0
                && $summary['fees']['assignments'] === 0);
    }
}
