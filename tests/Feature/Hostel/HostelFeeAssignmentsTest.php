<?php

namespace Tests\Feature\Hostel;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\FeePayment;
use App\Models\HostelAllocation;
use App\Models\HostelFeeAssignment;
use App\Models\HostelFeeStructure;
use Illuminate\Support\Str;
use Tests\TestCase;

class HostelFeeAssignmentsTest extends TestCase
{
    use HostelTestHelpers;

    private function year(College $college): AcademicYear
    {
        return AcademicYear::create([
            'college_id' => $college->id,
            'name' => 'Year '.Str::upper(Str::random(4)),
            'code' => Str::upper(Str::random(6)),
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
    }

    public function test_fee_assignment_requires_valid_allocation_and_snapshots_amount(): void
    {
        $college = $this->makeCollege('HFA01');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear, ['amount' => 8000]);

        $this->asCollege($college, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => now()->format('Y-m-d'),
            'status' => 'active',
        ])->assertRedirect(route('hostel-fees.index'));

        $assignment = $this->withTenant($college, fn () => HostelFeeAssignment::firstOrFail());
        $this->assertEquals(8000, (float) $assignment->assigned_amount, 'Amount must be snapshotted server-side.');
        $this->assertEquals($allocation->academic_year_id, $assignment->academic_year_id, 'Academic year must be stamped from allocation.');

        // Changing structure amount must NOT change existing assignment
        $structure->update(['amount' => 9000]);
        $assignment->refresh();
        $this->assertEquals(8000, (float) $assignment->assigned_amount, 'Snapshot must remain unchanged after structure edit.');
    }

    public function test_duplicate_active_assignment_prevention_and_overlap(): void
    {
        $college = $this->makeCollege('HFA02');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear);

        $this->asCollege($college, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'effective_until' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        // Overlapping period
        $this->asCollege($college, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => '2026-11-01',
            'effective_until' => '2027-02-28',
        ])->assertSessionHasErrors('effective_from');

        // Non-overlapping should succeed
        $this->asCollege($college, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => '2027-01-01',
            'effective_until' => '2027-04-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => HostelFeeAssignment::count()));
    }

    public function test_effective_date_validation(): void
    {
        $college = $this->makeCollege('HFA03');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear);

        $this->asCollege($college, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-10',
            'effective_until' => '2026-09-05',
        ])->assertSessionHasErrors('effective_until');
    }

    public function test_payment_collection_and_ledger_compatibility(): void
    {
        $college = $this->makeCollege('HFA04');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create', 'hostel_fees.collect', 'receipts.view']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear, ['amount' => 10000]);
        $assignment = $this->makeHostelFeeAssignment($college, $allocation, $structure, ['assigned_amount' => 10000]);

        // Collect partial payment
        $this->asCollege($college, $user)->post(route('hostel-fees.collect', $assignment), [
            'payment_date' => now()->format('Y-m-d'),
            'payment_mode' => 'cash',
            'amount' => 4000,
        ])->assertRedirect();

        $payment = $this->withTenant($college, fn () => FeePayment::firstOrFail());
        $this->assertEquals(4000, (float) $payment->amount);
        $this->assertEquals($assignment->id, $payment->hostel_fee_assignment_id);
        $this->assertNull($payment->student_fee_assignment_id);
        $this->assertNull($payment->transport_fee_assignment_id);

        // Ledger should show outstanding 6000
        $service = app(\App\Domain\Hostel\Services\HostelFeeService::class);
        $summary = $this->withTenant($college, fn () => $service->summaryFor($assignment->refresh()));
        $this->assertEquals(6000, $summary['outstanding']);
        $this->assertEquals(4000, $summary['net_collected']);

        // Second payment exceeding outstanding should fail
        $this->asCollege($college, $user)->post(route('hostel-fees.collect', $assignment), [
            'payment_date' => now()->format('Y-m-d'),
            'payment_mode' => 'cash',
            'amount' => 7000,
        ])->assertSessionHasErrors('amount');

        // Collect remaining
        $this->asCollege($college, $user)->post(route('hostel-fees.collect', $assignment), [
            'payment_date' => now()->format('Y-m-d'),
            'payment_mode' => 'cash',
            'amount' => 6000,
        ])->assertSessionHasNoErrors();

        $summary = $this->withTenant($college, fn () => $service->summaryFor($assignment->refresh()));
        $this->assertEquals(0, $summary['outstanding']);
        $this->assertEquals('paid', $summary['status']);

        // Receipt should be viewable via existing Finance receipt route
        $lastPayment = $this->withTenant($college, fn () => FeePayment::orderByDesc('id')->firstOrFail());
        $this->asCollege($college, $user)->get(route('receipts.show', $lastPayment))->assertOk();
        $this->asCollege($college, $user)->get(route('receipts.index'))->assertOk();
    }

    public function test_cancelled_allocation_cannot_be_charged(): void
    {
        $college = $this->makeCollege('HFA05');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.create']);
        $allocation = $this->makeHostelAllocation($college, null, null, ['status' => HostelAllocation::STATUS_CANCELLED]);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear);

        $this->asCollege($college, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocation->id,
            'hostel_fee_structure_id' => $structure->id,
            'effective_from' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors('hostel_allocation_id');
    }

    public function test_cross_tenant_fee_assignment_rejection(): void
    {
        $collegeA = $this->makeCollege('HFA06A');
        $collegeB = $this->makeCollege('HFA06B');
        $user = $this->makeUserWithPermissions($collegeA, ['hostel_fees.view', 'hostel_fees.create']);
        $allocationB = $this->makeHostelAllocation($collegeB);
        $structureA = $this->makeHostelFeeStructure($collegeA, $this->year($collegeA));

        $this->asCollege($collegeA, $user)->post(route('hostel-fees.store'), [
            'hostel_allocation_id' => $allocationB->id,
            'hostel_fee_structure_id' => $structureA->id,
            'effective_from' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors('hostel_allocation_id');
    }

    public function test_tenant_isolation(): void
    {
        $college = $this->makeCollege('HFA07');
        $other = $this->makeCollege('HFA07X');
        $user = $this->makeUserWithPermissions($college, ['hostel_fees.view', 'hostel_fees.update', 'hostel_fees.delete']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear);
        $mine = $this->makeHostelFeeAssignment($college, $allocation, $structure);

        $otherAllocation = $this->makeHostelAllocation($other);
        $otherStructure = $this->makeHostelFeeStructure($other, $otherAllocation->academicYear);
        $theirs = $this->makeHostelFeeAssignment($other, $otherAllocation, $otherStructure);

        $this->asCollege($college, $user)->get(route('hostel-fees.index'))->assertOk()->assertSee($structure->name)->assertDontSee($otherStructure->name);
        $this->asCollege($college, $user)->get(route('hostel-fees.edit', $theirs))->assertNotFound();
    }

    public function test_rbac(): void
    {
        $college = $this->makeCollege('HFA08');
        $viewer = $this->makeUserWithPermissions($college, ['hostel_fees.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $allocation = $this->makeHostelAllocation($college);
        $structure = $this->makeHostelFeeStructure($college, $allocation->academicYear);
        $assignment = $this->makeHostelFeeAssignment($college, $allocation, $structure);

        $this->asCollege($college, $nobody)->get(route('hostel-fees.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('hostel-fees.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('hostel-fees.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('hostel-fees.store'), [])->assertForbidden();
    }
}
