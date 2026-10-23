<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FeePayment;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Per-Fee-Head preview/confirm allocation, built on the existing assignment ledger. */
class FeeHeadAllocationTest extends TestCase
{
    use FeeStructureTestHelpers;

    public function test_preview_confirm_and_retry_create_immutable_head_snapshots_and_no_payments(): void
    {
        $college = $this->makeCollege('FHA01');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.view', 'student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FHA01');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FHA01');
        $heads = $this->feeHeads($college, $structure);

        $preview = $this->preview($college, $user, $structure, $heads->modelKeys(), [
            'selection_mode' => 'selected',
            'student_enrollment_ids' => [$fixture['enrollment']->id],
            'student_status' => 'active',
        ])->assertOk()->assertViewHas('result', fn (array $result) => $result['eligible_count'] === 2
            && $result['student_count'] === 1
            && $result['skipped_count'] === 0
            && $result['total_amount'] === 26500.0);

        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()), 'Preview must not write.');
        $token = $preview->viewData('allocationToken');
        $this->assertIsString($token);

        $this->confirm($college, $user, $token)->assertOk()->assertViewHas('result', fn (array $result) => $result['created_count'] === 2
            && $result['skipped_count'] === 0
            && ! $result['idempotent']);

        $assignments = $this->withTenant($college, fn () => StudentFeeAssignment::query()->orderBy('id')->get());
        $this->assertCount(2, $assignments);
        $this->assertSame([25000.0, 1500.0], $assignments->map(fn ($assignment) => (float) $assignment->assigned_amount)->all());
        $this->assertSame($heads->modelKeys(), $assignments->pluck('fee_structure_item_id')->all());
        $this->assertSame(['Tuition Fee', 'Library Fee'], $assignments->pluck('fee_head_name')->all());
        $this->assertSame(1, $assignments->pluck('allocation_batch_id')->unique()->count());
        $this->assertSame(0, $this->withTenant($college, fn () => FeePayment::query()->count()), 'Allocation must never collect money.');

        $heads->first()->update(['name' => 'Repriced Tuition', 'amount' => 90000]);
        $this->assertSame(25000.0, (float) $assignments->first()->fresh()->assigned_amount);
        $this->assertSame('Tuition Fee', $assignments->first()->fresh()->fee_head_name);

        $this->confirm($college, $user, $token)->assertOk()->assertViewHas('result', fn (array $result) => $result['idempotent']
            && $result['existing_count'] === 2
            && $result['created_count'] === 0);
        $this->assertSame(2, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
        $this->assertSame(1, $this->withTenant($college, fn () => AuditLog::query()->where('action', 'student_fee_assignments.allocation_completed')->count()));
    }

    public function test_a_head_price_change_after_preview_requires_a_fresh_preview(): void
    {
        $college = $this->makeCollege('FHA01P');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FHA01P');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FHA01P');
        $head = $this->feeHeads($college, $structure)->first();

        $preview = $this->preview($college, $user, $structure, [$head->id], [
            'selection_mode' => 'selected', 'student_enrollment_ids' => [$fixture['enrollment']->id],
        ])->assertOk();
        $head->update(['amount' => 30000]);

        $this->confirm($college, $user, $preview->viewData('allocationToken'))
            ->assertSessionHasErrors('fee_head_ids');
        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
    }

    public function test_all_filtered_scope_ignores_browser_ids_and_reruns_filters_at_confirmation(): void
    {
        $college = $this->makeCollege('FHA02');
        $other = $this->makeCollege('FHA02X');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FHA02');
        $structure = $this->makeFeeStructure($college, $ctx);
        $local = $this->makeFinanceEnrollment($college, $ctx, 'FHA02A');
        $inactive = $this->makeFinanceEnrollment($college, $ctx, 'FHA02B');
        $inactive['student']->update(['status' => 'inactive']);
        $foreignCtx = $this->makeFinanceContext($other, 'FHA02X');
        $foreign = $this->makeFinanceEnrollment($other, $foreignCtx, 'FHA02X');
        $heads = $this->feeHeads($college, $structure);

        $preview = $this->preview($college, $user, $structure, [$heads->first()->id], [
            'selection_mode' => 'all_filtered',
            // These IDs must be ignored in all-filtered mode.
            'student_enrollment_ids' => [$foreign['enrollment']->id, $inactive['enrollment']->id],
            'student_status' => 'active',
            'program_id' => $ctx['prog']->id,
        ])->assertOk()->assertViewHas('result', fn (array $result) => $result['selection_mode'] === 'all_filtered'
            && $result['eligible_count'] === 1
            && $result['rows'][0]['student_enrollment_id'] === $local['enrollment']->id);

        // The preview is not authority: the student no longer matches when the
        // encrypted confirmation is submitted, so no assignment is created.
        $local['student']->update(['status' => 'inactive']);
        $this->confirm($college, $user, $preview->viewData('allocationToken'))
            ->assertOk()->assertViewHas('result', fn (array $result) => $result['created_count'] === 0);
        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
        $this->assertSame(0, $this->withTenant($other, fn () => StudentFeeAssignment::query()->count()));
    }

    public function test_all_filtered_confirmation_includes_records_beyond_the_first_page(): void
    {
        $college = $this->makeCollege('FHA02P');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FHA02P');
        $structure = $this->makeFeeStructure($college, $ctx);
        $head = $this->feeHeads($college, $structure)->first();
        foreach (range(1, 16) as $index) {
            $this->makeFinanceEnrollment($college, $ctx, 'FHA02P'.$index);
        }

        $preview = $this->preview($college, $user, $structure, [$head->id], [
            'selection_mode' => 'all_filtered',
            'student_enrollment_ids' => [],
            'student_status' => 'active',
            'program_id' => $ctx['prog']->id,
        ])->assertOk()->assertViewHas('result', fn (array $result) => $result['eligible_count'] === 16);
        $this->confirm($college, $user, $preview->viewData('allocationToken'))
            ->assertOk()->assertViewHas('result', fn (array $result) => $result['created_count'] === 16);
        $this->assertSame(16, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
        $this->assertSame(0, $this->withTenant($college, fn () => FeePayment::query()->count()));
    }

    public function test_duplicates_whole_structure_overlap_and_multiple_enrollments_are_partial_outcomes(): void
    {
        $college = $this->makeCollege('FHA03');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.create', 'student_fee_assignments.view']);
        $ctx = $this->makeFinanceContext($college, 'FHA03');
        $structure = $this->makeFeeStructure($college, $ctx);
        $heads = $this->feeHeads($college, $structure);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FHA03');
        $secondEnrollment = StudentEnrollment::create([
            'college_id' => $college->id,
            'student_id' => $fixture['student']->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'enrollment_number' => 'ENR-FHA03-SECOND',
            'enrollment_date' => '2026-08-06',
            'status' => 'active',
        ]);

        // One existing head duplicates; the other remains eligible. The second
        // enrollment for the same student is then skipped by this batch.
        StudentFeeAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $fixture['enrollment']->id,
            'fee_structure_id' => $structure->id,
            'fee_structure_item_id' => $heads->first()->id,
            'fee_head_name' => $heads->first()->name,
            'assigned_amount' => $heads->first()->amount,
            'assigned_at' => '2026-08-01',
            'status' => 'active',
        ]);

        $preview = $this->preview($college, $user, $structure, $heads->modelKeys(), [
            'selection_mode' => 'selected',
            'student_enrollment_ids' => [$fixture['enrollment']->id, $secondEnrollment->id],
        ])->assertOk();
        $result = $preview->viewData('result');
        $this->assertSame(1, $result['eligible_count']);
        $this->assertSame($heads->last()->id, $result['rows'][0]['fee_structure_item_id']);
        $this->assertSame(3, $result['skipped_count']);
        $this->assertContains('duplicate_fee_head', array_column($result['skips'], 'reason'));
        $this->assertContains('another_enrollment_for_student', array_column($result['skips'], 'reason'));

        $this->confirm($college, $user, $preview->viewData('allocationToken'))->assertOk();
        $this->assertSame(2, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));

        // Whole-structure creation is still available, but not while any payable
        // per-head assignment of that structure overlaps the enrollment.
        $this->asCollege($college, $user)->post(route('student-fee-assignments.store'), [
            'student_enrollment_id' => $fixture['enrollment']->id,
            'fee_structure_id' => $structure->id,
            'assigned_at' => '2026-08-10',
            'status' => 'active',
        ])->assertSessionHasErrors('fee_structure_id');

        $wholeFixture = $this->makeFinanceEnrollment($college, $ctx, 'FHA03W');
        $this->assignFeeStructure($college, $user, $wholeFixture['enrollment'], $structure);
        $wholePreview = $this->preview($college, $user, $structure, [$heads->first()->id], [
            'selection_mode' => 'selected', 'student_enrollment_ids' => [$wholeFixture['enrollment']->id],
        ])->assertOk();
        $this->assertSame(0, $wholePreview->viewData('result')['eligible_count']);
        $this->assertSame('whole_structure_overlap', $wholePreview->viewData('result')['skips'][0]['reason']);
    }

    public function test_cancelled_or_deleted_head_history_does_not_block_reallocation(): void
    {
        $college = $this->makeCollege('FHA04');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.create']);
        $ctx = $this->makeFinanceContext($college, 'FHA04');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FHA04');
        $head = $this->feeHeads($college, $structure)->first();

        $old = StudentFeeAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $fixture['enrollment']->id,
            'fee_structure_id' => $structure->id,
            'fee_structure_item_id' => $head->id,
            'fee_head_name' => $head->name,
            'assigned_amount' => $head->amount,
            'assigned_at' => '2026-08-01',
            'status' => 'cancelled',
        ]);

        $preview = $this->preview($college, $user, $structure, [$head->id], [
            'selection_mode' => 'selected', 'student_enrollment_ids' => [$fixture['enrollment']->id],
        ])->assertOk()->assertViewHas('result', fn (array $result) => $result['eligible_count'] === 1);
        $this->confirm($college, $user, $preview->viewData('allocationToken'))->assertOk();
        $this->assertSame(2, $this->withTenant($college, fn () => StudentFeeAssignment::withTrashed()->count()));
        $this->assertSame('cancelled', $old->fresh()->status);

        $active = $this->withTenant($college, fn () => StudentFeeAssignment::query()->where('status', 'active')->firstOrFail());
        $active->delete();
        $afterDelete = $this->preview($college, $user, $structure, [$head->id], [
            'selection_mode' => 'selected', 'student_enrollment_ids' => [$fixture['enrollment']->id],
        ])->assertOk()->assertViewHas('result', fn (array $result) => $result['eligible_count'] === 1);
        $this->confirm($college, $user, $afterDelete->viewData('allocationToken'))->assertOk();
        $this->assertSame(3, $this->withTenant($college, fn () => StudentFeeAssignment::withTrashed()->count()));
    }

    public function test_permission_tenant_scope_fee_head_ownership_and_token_integrity_are_enforced(): void
    {
        $college = $this->makeCollege('FHA05');
        $other = $this->makeCollege('FHA05X');
        $user = $this->makeUserWithPermissions($college, ['student_fee_assignments.create']);
        $stranger = $this->makeUserWithPermissions($college, ['student_fee_assignments.view']);
        $ctx = $this->makeFinanceContext($college, 'FHA05');
        $foreignCtx = $this->makeFinanceContext($other, 'FHA05X');
        $structure = $this->makeFeeStructure($college, $ctx);
        $foreignStructure = $this->makeFeeStructure($other, $foreignCtx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FHA05');
        $foreignFixture = $this->makeFinanceEnrollment($other, $foreignCtx, 'FHA05X');
        $head = $this->feeHeads($college, $structure)->first();
        $foreignHead = $this->feeHeads($other, $foreignStructure)->first();

        $payload = [
            'fee_structure_id' => $structure->id,
            'fee_head_ids' => [$head->id],
            'selection_mode' => 'selected',
            'student_enrollment_ids' => [$fixture['enrollment']->id],
            'assigned_at' => '2026-08-10',
            'status' => 'active',
        ];
        $this->asCollege($college, $stranger)->post(route('student-fee-assignments.allocation.preview'), $payload)->assertForbidden();

        $this->preview($college, $user, $structure, [$foreignHead->id], [
            'selection_mode' => 'selected', 'student_enrollment_ids' => [$fixture['enrollment']->id],
        ])->assertSessionHasErrors('fee_head_ids');

        $foreignPreview = $this->preview($college, $user, $structure, [$head->id], [
            'selection_mode' => 'selected', 'student_enrollment_ids' => [$foreignFixture['enrollment']->id],
        ])->assertOk()->assertViewHas('result', fn (array $result) => $result['eligible_count'] === 0
            && $result['skipped_count'] === 1
            && $result['skips'][0]['reason'] === 'not_in_filtered_scope');

        $this->asCollege($college, $user)->post(route('student-fee-assignments.allocation.confirm'), [
            'allocation_token' => 'tampered-token',
        ])->assertSessionHasErrors('allocation_token');

        // Fee Category is classification only and can never substitute for a
        // concrete FeeStructureItem selection.
        $this->asCollege($college, $user)->post(route('student-fee-assignments.allocation.preview'), [
            'fee_structure_id' => $structure->id,
            'fee_category_id' => 1,
            'selection_mode' => 'selected',
            'student_enrollment_ids' => [$fixture['enrollment']->id],
            'assigned_at' => '2026-08-10',
            'status' => 'active',
        ])->assertSessionHasErrors('fee_head_ids');
        $this->assertSame(0, $this->withTenant($college, fn () => StudentFeeAssignment::query()->count()));
        $this->assertSame(0, $this->withTenant($other, fn () => StudentFeeAssignment::query()->count()));
    }

    private function feeHeads($college, $structure)
    {
        return $this->withTenant($college, fn () => $structure->allItems()->orderBy('id')->get());
    }

    private function preview($college, $user, $structure, array $headIds, array $overrides = []): TestResponse
    {
        return $this->asCollege($college, $user)->post(route('student-fee-assignments.allocation.preview'), array_merge([
            'fee_structure_id' => $structure->id,
            'fee_head_ids' => $headIds,
            'selection_mode' => 'selected',
            'student_enrollment_ids' => [],
            'assigned_at' => '2026-08-10',
            'status' => 'active',
            'remarks' => 'Per-head test allocation',
        ], $overrides));
    }

    private function confirm($college, $user, string $token): TestResponse
    {
        return $this->asCollege($college, $user)->post(route('student-fee-assignments.allocation.confirm'), [
            'allocation_token' => $token,
        ]);
    }
}
