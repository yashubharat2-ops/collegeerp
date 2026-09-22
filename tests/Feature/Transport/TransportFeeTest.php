<?php

namespace Tests\Feature\Transport;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Models\{AcademicYear, College, FeePayment, Permission, Role, Student, StudentEnrollment, StudentTransportAssignment, StudentTransportFeeAssignment, TransportFeeStructure, TransportRoute, TransportStop, User};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transport Phase 2 — Transport Fees: the transport-side pricing master plus
 * student transport fee assignments. Money moves only through the EXISTING
 * Finance rows (fee_payments), so collections, receipts and outstanding use the
 * same architecture as tuition — no second payment path.
 */
class TransportFeeTest extends TestCase
{
    private const PERMISSIONS = [
        'transport_fees.view', 'transport_fees.create', 'transport_fees.update', 'transport_fees.delete',
    ];

    private function college(string $code): College
    {
        return College::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'status' => 'active']);
    }

    private function user(College $college, ?array $permissions = null): User
    {
        $user = User::create(['name' => 'Fee Staff', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $role = Role::create(['college_id' => $college->id, 'name' => 'Transport Fees', 'slug' => (string) Str::uuid(), 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions ?? self::PERMISSIONS)->pluck('id'));
        $user->roles()->attach($role->id, ['college_id' => $college->id]);
        return $user;
    }

    private function login(College $college, ?array $permissions = null): User
    {
        $user = $this->user($college, $permissions);
        $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
        return $user;
    }

    private function fixture(string $class, College $college, array $data)
    {
        $record = new $class(array_diff_key($data, ['route_id' => true]));
        $record->college_id = $college->id;
        if (isset($data['route_id'])) {
            $record->route_id = $data['route_id'];
        }
        $record->save();
        return $record;
    }

    private function year(College $college): AcademicYear
    {
        return $this->fixture(AcademicYear::class, $college, [
            'name' => 'Year '.Str::upper(Str::random(4)),
            'code' => Str::upper(Str::random(6)),
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
    }

    private function transportAssignment(College $college, AcademicYear $year, ?TransportRoute $givenRoute = null, ?TransportStop $givenStop = null, string $status = 'active'): StudentTransportAssignment
    {
        $student = $this->fixture(Student::class, $college, [
            'student_number' => 'STU-'.Str::upper(Str::random(6)),
            'first_name' => 'Fee',
            'last_name' => 'Student',
            'status' => 'active',
        ]);
        $enrollment = $this->fixture(StudentEnrollment::class, $college, [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-'.Str::upper(Str::random(6)),
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $route = $givenRoute ?? $this->fixture(TransportRoute::class, $college, ['name' => 'Route'.Str::upper(Str::random(4)), 'code' => Str::upper(Str::random(6)), 'status' => 'active']);
        $stop = $givenStop ?? $this->fixture(TransportStop::class, $college, ['route_id' => $route->id, 'name' => 'Stop'.Str::upper(Str::random(4)), 'code' => Str::upper(Str::random(6)), 'sequence' => 1, 'status' => 'active']);

        return $this->fixture(StudentTransportAssignment::class, $college, [
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => $status,
        ]);
    }

    private function structure(College $college, AcademicYear $year, array $extra = []): TransportFeeStructure
    {
        return $this->fixture(TransportFeeStructure::class, $college, array_merge([
            'academic_year_id' => $year->id,
            'name' => 'City Route Fee '.Str::upper(Str::random(4)),
            'code' => Str::upper(Str::random(6)),
            'amount' => 1200.50,
            'effective_from' => '2026-07-01',
            'status' => 'active',
        ], $extra));
    }

    private function structurePayload(AcademicYear $year, array $extra = []): array
    {
        return array_merge([
            'academic_year_id' => $year->id,
            'name' => 'City Route Fee',
            'code' => ' trf-'.Str::lower(Str::random(4)).' ',
            'amount' => '1200.50',
            'effective_from' => '2026-07-01',
            'effective_until' => '2027-06-30',
            'status' => 'active',
        ], $extra);
    }

    public function test_structure_crud_normalizes_code_and_never_hardcodes_amounts(): void
    {
        $a = $this->college('TFS');
        $year = $this->year($a);
        $user = $this->login($a);

        $this->get(route('transport-fee-structures.create'))->assertOk();
        $payload = $this->structurePayload($year, ['college_id' => 999, 'created_by' => 999]);
        $this->post(route('transport-fee-structures.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('transport-fee-structures.index'));

        $structure = TransportFeeStructure::withoutGlobalScopes()->firstOrFail();
        $this->assertEquals($a->id, $structure->college_id);
        $this->assertEquals($user->id, $structure->created_by);
        $this->assertSame(strtoupper(trim($payload['code'])), $structure->code);
        $this->assertSame('1200.50', (string) $structure->amount);

        // Amount / period validation.
        foreach ([['amount' => '0'], ['amount' => '-5'], ['amount' => '10.999'], ['amount' => 'abc'], ['effective_until' => '2026-06-01']] as $bad) {
            $this->post(route('transport-fee-structures.store'), $this->structurePayload($year, $bad))->assertSessionHasErrors(array_key_first($bad));
        }

        // Codes stay reserved including archived records.
        $this->put(route('transport-fee-structures.update', $structure->id), $this->structurePayload($year, ['code' => $structure->code, 'amount' => '900.00']))->assertSessionHasNoErrors();
        $this->assertSame('900.00', (string) $structure->fresh()->amount);
        $this->post(route('transport-fee-structures.store'), $this->structurePayload($year, ['code' => $structure->code]))->assertSessionHasErrors('code');
        $this->delete(route('transport-fee-structures.destroy', $structure->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('transport_fee_structures', ['id' => $structure->id, 'updated_by' => $user->id]);
        $this->post(route('transport-fee-structures.store'), $this->structurePayload($year, ['code' => $structure->code]))->assertSessionHasErrors('code');
        foreach (['transport_fee_structures.created', 'transport_fee_structures.updated', 'transport_fee_structures.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'action' => $action, 'subject_id' => $structure->id]);
        }
    }

    public function test_structure_rejects_foreign_or_incoherent_narrowing(): void
    {
        $a = $this->college('TSN');
        $b = $this->college('TSN2');
        $year = $this->year($a);
        $yearB = $this->year($b);
        $route = $this->fixture(TransportRoute::class, $a, ['name' => 'R', 'code' => Str::upper(Str::random(6)), 'status' => 'active']);
        $route2 = $this->fixture(TransportRoute::class, $a, ['name' => 'R2', 'code' => Str::upper(Str::random(6)), 'status' => 'active']);
        $stopOn2 = $this->fixture(TransportStop::class, $a, ['route_id' => $route2->id, 'name' => 'S2', 'code' => Str::upper(Str::random(6)), 'sequence' => 1, 'status' => 'active']);
        $this->login($a);

        $this->post(route('transport-fee-structures.store'), $this->structurePayload($yearB))->assertSessionHasErrors('academic_year_id');
        $this->post(route('transport-fee-structures.store'), $this->structurePayload($year, ['transport_route_id' => $route->id, 'transport_stop_id' => $stopOn2->id]))
            ->assertSessionHasErrors('transport_stop_id');
        $this->post(route('transport-fee-structures.store'), $this->structurePayload($year, [
            'transport_route_id' => $route->id,
            'transport_stop_id' => $this->fixture(TransportStop::class, $b, ['route_id' => $this->fixture(TransportRoute::class, $b, ['name' => 'X', 'code' => Str::upper(Str::random(6)), 'status' => 'active'])->id, 'name' => 'X', 'code' => Str::upper(Str::random(6)), 'sequence' => 1, 'status' => 'active'])->id,
        ]))->assertSessionHasErrors('transport_stop_id');
        $this->assertDatabaseCount('transport_fee_structures', 0);
    }

    public function test_fee_assignment_snapshots_amount_and_requires_valid_context(): void
    {
        $a = $this->college('TFA');
        $year = $this->year($a);
        $assignment = $this->transportAssignment($a, $year);
        $structure = $this->structure($a, $year);
        $this->login($a);

        $this->get(route('transport-fees.create'))->assertOk();
        // The browser-sent amount is NEVER read — the structure's price is
        // snapshotted server-side.
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
            'amount' => 1.0,
            'academic_year_id' => 999,
            'college_id' => 999,
        ])->assertSessionHasNoErrors();

        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('1200.50', (string) $fee->amount);
        $this->assertEquals($year->id, $fee->academic_year_id);
        $this->assertEquals($a->id, $fee->college_id);

        // Overlapping ACTIVE periods on one transport assignment are refused.
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-10-01',
            'status' => 'active',
        ])->assertSessionHasErrors('effective_from');
        // An open-ended period overlaps everything after it.
        $this->put(route('transport-fees.update', $fee->id), ['effective_from' => '2026-09-01', 'effective_until' => null, 'status' => 'active'])->assertSessionHasNoErrors();
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2030-01-01',
            'status' => 'active',
        ])->assertSessionHasErrors('effective_from');
        // Back-to-back periods (no overlap) are fine.
        $this->put(route('transport-fees.update', $fee->id), ['effective_from' => '2026-09-01', 'effective_until' => '2026-12-31', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2027-01-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, StudentTransportFeeAssignment::withoutGlobalScopes()->count());
    }

    public function test_fee_assignment_requires_matching_active_transport_and_structure(): void
    {
        $a = $this->college('TFC');
        $year = $this->year($a);
        $cancelled = $this->transportAssignment($a, $year, null, null, 'cancelled');
        $active = $this->transportAssignment($a, $year);
        $structure = $this->structure($a, $year);
        $year2 = $this->year($a);
        $this->login($a);

        // Only an ACTIVE transport assignment can be charged.
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $cancelled->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasErrors('student_transport_assignment_id');

        // The structure's year / narrowing must fit the transport assignment.
        $otherYearStructure = $this->structure($a, $year2);
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $active->id,
            'transport_fee_structure_id' => $otherYearStructure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasErrors('transport_fee_structure_id');

        $route2 = $this->fixture(TransportRoute::class, $a, ['name' => 'Other', 'code' => Str::upper(Str::random(6)), 'status' => 'active']);
        $stop2 = $this->fixture(TransportStop::class, $a, ['route_id' => $route2->id, 'name' => 'O', 'code' => Str::upper(Str::random(6)), 'sequence' => 1, 'status' => 'active']);
        $narrowed = $this->structure($a, $year, ['transport_route_id' => $route2->id, 'transport_stop_id' => $stop2->id]);
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $active->id,
            'transport_fee_structure_id' => $narrowed->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasErrors('transport_fee_structure_id');

        // Foreign structures never validate.
        $b = $this->college('TFC2');
        $foreign = $this->structure($b, $this->year($b));
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $active->id,
            'transport_fee_structure_id' => $foreign->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasErrors('transport_fee_structure_id');
        $this->assertDatabaseCount('student_transport_fee_assignments', 0);
    }

    public function test_update_is_bookkeeping_only_and_delete_rules_preserve_history(): void
    {
        $a = $this->college('TFU');
        $year = $this->year($a);
        $assignment = $this->transportAssignment($a, $year);
        $structure = $this->structure($a, $year);
        $other = $this->structure($a, $year);
        $user = $this->login($a);
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();

        // Crafted re-pricing / re-pointing is stripped — financial history holds.
        $this->put(route('transport-fees.update', $fee->id), [
            'effective_from' => '2026-09-15',
            'effective_until' => '2027-03-31',
            'status' => 'active',
            'remarks' => 'scholarship window',
            'amount' => 1,
            'transport_fee_structure_id' => $other->id,
            'student_transport_assignment_id' => 999,
            'academic_year_id' => 999,
        ])->assertSessionHasNoErrors();
        $fee->refresh();
        $this->assertSame('1200.50', (string) $fee->amount);
        $this->assertEquals($structure->id, $fee->transport_fee_structure_id);
        $this->assertEquals($assignment->id, $fee->student_transport_assignment_id);
        $this->assertSame('2026-09-15', $fee->effective_from->format('Y-m-d'));
        $this->assertSame('scholarship window', $fee->remarks);

        // Money movements block deletion; otherwise the delete stays soft.
        $payment = app(FeeCollectionService::class)->collectTransportFee($fee, [
            'payment_date' => now()->toDateString(), 'payment_mode' => FeePayment::MODE_CASH, 'amount' => 500,
        ], $user);
        $this->delete(route('transport-fees.destroy', $fee->id))->assertSessionHasErrors('status');
        $payment->delete();
        $this->delete(route('transport-fees.destroy', $fee->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('student_transport_fee_assignments', ['id' => $fee->id, 'updated_by' => $user->id]);
        foreach (['student_transport_fee_assignments.updated', 'student_transport_fee_assignments.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'subject_id' => $fee->id]);
        }
    }

    public function test_collection_reuses_the_existing_finance_payment_rows(): void
    {
        $a = $this->college('TFCOL');
        $year = $this->year($a);
        $assignment = $this->transportAssignment($a, $year);
        $structure = $this->structure($a, $year);
        // The collector may also see the existing Finance screens the payment
        // lands on (fee collections + receipts) — transport payments live there.
        $user = $this->login($a, array_merge(self::PERMISSIONS, ['fee_collections.view', 'receipts.view']));
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();

        // Over-collection is rejected against the derived outstanding balance.
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 2000,
        ])->assertSessionHasErrors('amount');

        // A collection is an ordinary Finance payment row (same series/receipts).
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => '2026-09-05',
            'payment_mode' => 'upi',
            'amount' => '500.25',
            'reference_number' => 'UTR-1',
            'submission_token' => Str::uuid()->toString(),
        ])->assertSessionHasNoErrors();

        $payment = FeePayment::withoutGlobalScopes()->where('transport_fee_assignment_id', $fee->id)->firstOrFail();
        $this->assertEquals($assignment->student_enrollment_id, $payment->student_enrollment_id);
        $this->assertNull($payment->student_fee_assignment_id);
        $this->assertNull($payment->fee_structure_id);
        $this->assertSame('500.25', (string) $payment->amount);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertEquals($user->id, $payment->collected_by);
        $this->assertNotEmpty($payment->payment_number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fee_payments.collected', 'subject_id' => $payment->id]);

        // The ledger is derived live: assigned − valid payments.
        $summary = app(\App\Domain\Transport\Services\TransportFeeService::class)->summaryFor($fee->fresh());
        $this->assertSame(1200.50, $summary['assigned']);
        $this->assertSame(500.25, $summary['net_collected']);
        $this->assertSame(700.25, $summary['outstanding']);

        // The same payment appears on the EXISTING Fee Collection screen.
        $this->get(route('fee-collections.index'))->assertOk()->assertSee($payment->payment_number);
        // And its receipt renders through the shared receipt projection.
        $this->get(route('receipts.show', $payment))->assertOk()->assertSee('Transport Fee');

        // Bank-side duplicate reference/amount/date re-submissions are refused.
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => '2026-09-05', 'payment_mode' => 'upi', 'amount' => '500.25', 'reference_number' => 'UTR-1',
        ])->assertSessionHasErrors('reference_number');
    }

    public function test_cancelled_assignment_cannot_be_collected_against(): void
    {
        $a = $this->college('TFCAN');
        $year = $this->year($a);
        $assignment = $this->transportAssignment($a, $year);
        $structure = $this->structure($a, $year);
        $this->login($a);
        $this->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();
        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();
        $fee->update(['status' => 'cancelled']);

        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 10,
        ])->assertSessionHasErrors('student_transport_fee_assignment_id');
        $this->assertDatabaseCount('fee_payments', 0);
    }

    public function test_every_endpoint_is_tenant_scoped(): void
    {
        $a = $this->college('TFTEN');
        $b = $this->college('TFTEN2');
        $yearB = $this->year($b);
        $structureB = $this->structure($b, $yearB);
        $feeB = $this->fixture(StudentTransportFeeAssignment::class, $b, [
            'student_transport_assignment_id' => $this->transportAssignment($b, $yearB)->id,
            'transport_fee_structure_id' => $structureB->id,
            'academic_year_id' => $yearB->id,
            'amount' => 100,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->login($a);
        foreach ([
            ['transport-fee-structures.edit', ['transport_fee_structure' => $structureB->id]],
            ['transport-fees.edit', ['transport_fee' => $feeB->id]],
        ] as [$name, $params]) {
            $this->get(route($name, $params))->assertNotFound();
        }
        $this->put(route('transport-fees.update', $feeB->id), [])->assertNotFound();
        $this->post(route('transport-fees.collect', $feeB->id), [])->assertNotFound();
        $this->delete(route('transport-fees.destroy', $feeB->id))->assertNotFound();
        $this->delete(route('transport-fee-structures.destroy', $structureB->id))->assertNotFound();
        $this->get(route('transport-fees.index'))->assertViewHas('feeAssignments', fn ($rows) => ! $rows->contains('id', $feeB->id));
    }

    public function test_rbac_gates_every_action_including_collect(): void
    {
        $a = $this->college('TFRBAC');
        $year = $this->year($a);
        $assignment = $this->transportAssignment($a, $year);
        $structure = $this->structure($a, $year);
        $fee = $this->fixture(StudentTransportFeeAssignment::class, $a, [
            'student_transport_assignment_id' => $assignment->id,
            'transport_fee_structure_id' => $structure->id,
            'academic_year_id' => $year->id,
            'amount' => 100,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->login($a, []);
        $this->get(route('transport-fees.index'))->assertForbidden();
        $this->get(route('transport-fee-structures.index'))->assertForbidden();
        $this->post(route('transport-fees.store'), [])->assertForbidden();
        $this->post(route('transport-fees.collect', $fee->id), [])->assertForbidden();

        // Viewing does not imply collecting (collect is an update-level action).
        $this->login($a, ['transport_fees.view']);
        $this->get(route('transport-fees.index'))->assertOk();
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 1,
        ])->assertForbidden();

        $this->login($a, ['transport_fees.view', 'transport_fees.update']);
        $this->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => now()->toDateString(), 'payment_mode' => 'cash', 'amount' => 1,
        ])->assertSessionHasNoErrors();
    }
}
