<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Finance\Services\FeeDuesService;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\College;
use App\Models\FeePayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\TransportFeeStructure;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use Illuminate\Support\Str;
use App\Services\Approval\ApprovalRegistry;
use Tests\TestCase;

/**
 * Approval Management → Fees → Fee Collection (`fees.fee_collection`).
 *
 * The setting is the switch between "a collection is money the moment it is
 * recorded" (the default, and the behaviour when the toggle is off) and "a
 * collection is a request that only becomes money when an authorized approver
 * finalizes it".
 *
 * These tests pin the parts that are easy to get wrong and expensive to get
 * wrong: a pending collection must be invisible to every balance, receipt and
 * report; approval must be the only way for it to become money; rejection must
 * keep the row and never count it; the existing safeguards (self-approval,
 * tenant scoping, permissions, duplicate submission) must still hold; and
 * turning the setting off must restore the previous behaviour exactly.
 */
class FeeCollectionApprovalTest extends TestCase
{
    use FeeStructureTestHelpers;

    /** Permissions an accountant needs to record a collection. */
    private const COLLECTOR = [
        'fee_collections.view',
        'fee_collections.create',
        'fee_collections.update',
        'fee_collections.delete',
        'receipts.view',
        'receipts.print',
    ];

    /** Permissions an approver (College Admin) needs. */
    private const APPROVER = [
        'fee_collections.view',
        'fee_collections.approve',
        'receipts.view',
        'receipts.print',
    ];

    // ---------------------------------------------------------------- fixtures

    /**
     * One college with an accountant and an unrelated approver, plus a payable
     * fee assignment of 26,500.
     *
     * @return array{college: College, collector: User, approver: User, assignment: StudentFeeAssignment}
     */
    private function fixture(string $code, bool $approvalEnabled, float $tuition = 25000, float $library = 1500): array
    {
        $college = $this->makeCollege($code);
        $collector = $this->makeUserWithPermissions($college, self::COLLECTOR);
        $approver = $this->makeUserWithPermissions($college, self::APPROVER);
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx, [], [
            ['name' => 'Tuition Fee', 'amount' => $tuition, 'sort_order' => 1],
            ['name' => 'Library Fee', 'amount' => $library, 'sort_order' => 2],
        ]);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, $code);
        $assignment = $this->assignFeeStructure($college, $collector, $fixture['enrollment'], $structure);

        if ($approvalEnabled) {
            $this->enableApproval($college);
        }

        return compact('college', 'collector', 'approver', 'assignment');
    }

    private function enableApproval(College $college, bool $required = true): ApprovalSetting
    {
        return ApprovalSetting::withoutGlobalScopes()->updateOrCreate([
            'college_id' => $college->id,
            'module' => 'fees',
            'action' => ApprovalRegistry::ACTION_FEE_COLLECTION,
        ], [
            'is_required' => $required,
            'label' => 'Fee Collection',
        ]);
    }

    /**
     * A real platform Super Admin: the reserved `super-admin` role attached with
     * a null college pivot, exactly as DatabaseSeeder grants it.
     */
    private function makeSuperAdminUser(College $college): User
    {
        $user = User::create([
            'name' => 'Platform Super',
            'email' => 'super-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        $role = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $user->roles()->attach($role->id, ['college_id' => null]);

        return $user;
    }

    /**
     * The reserved College Admin role OF THIS COLLEGE, attached the way the
     * CollegeAdminRestrictionTest and the seeder attach it.
     */
    private function makeCollegeAdminUser(College $college): User
    {
        $role = Role::query()->firstOrCreate(
            ['college_id' => $college->id, 'slug' => Role::COLLEGE_ADMIN_SLUG],
            ['name' => 'College Admin', 'is_system' => true, 'is_active' => true],
        );
        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        $user = User::create([
            'name' => 'College Admin',
            'email' => 'college-admin-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        return $user;
    }

    /** Create a pending collection plus its approval request, as the service would. */
    private function queuePendingCollection(College $college, User $requester, StudentFeeAssignment $assignment, float $amount): FeePayment
    {
        $payment = $this->withTenant($college, fn () => FeePayment::create([
            'college_id' => $college->id,
            'student_fee_assignment_id' => $assignment->id,
            'student_enrollment_id' => $assignment->student_enrollment_id,
            'fee_structure_id' => $assignment->fee_structure_id,
            // Reference data only: this fixture writes the row directly so the
            // test controls the workflow state, and the number just has to be
            // unique inside the college like a real generated one.
            'payment_number' => 'PAY-TEST-'.Str::upper(Str::random(8)),
            'payment_date' => '2026-08-15',
            'payment_mode' => FeePayment::MODE_CASH,
            'amount' => $amount,
            'status' => FeePayment::STATUS_PENDING_APPROVAL,
            'collected_by' => $requester->id,
            'created_by' => $requester->id,
        ]));

        $this->withTenant($college, fn () => app(\App\Services\Approval\ApprovalService::class)->submitRequest([
            'module' => 'fees',
            'action' => ApprovalRegistry::ACTION_FEE_COLLECTION,
            'target_type' => 'fees.'.ApprovalRegistry::ACTION_FEE_COLLECTION,
            'target_id' => $payment->id,
            'title' => 'Fee collection '.$payment->payment_number,
            'payload' => ['payment_number' => $payment->payment_number, 'amount' => $amount],
        ], (int) $college->id, (int) $requester->getKey()));

        return $payment;
    }

    /** The POST payload the collection form sends. */
    private function collectPayload(StudentFeeAssignment $assignment, float $amount, array $overrides = []): array
    {
        return array_merge([
            'student_fee_assignment_id' => $assignment->id,
            'payment_date' => '2026-08-15',
            'payment_mode' => FeePayment::MODE_CASH,
            'amount' => $amount,
            'remarks' => 'Second instalment',
            'submission_token' => Str::uuid()->toString(),
        ], $overrides);
    }

    /** The single payment row of the fixture, read outside the request cycle. */
    private function paymentOf(College $college): FeePayment
    {
        return $this->withTenant($college, fn () => FeePayment::query()->latest('id')->firstOrFail());
    }

    private function approvalRequestOf(College $college, FeePayment $payment): ?ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('module', 'fees')
            ->where('action', ApprovalRegistry::ACTION_FEE_COLLECTION)
            ->where('target_type', 'fees.'.ApprovalRegistry::ACTION_FEE_COLLECTION)
            ->where('target_id', $payment->getKey())
            ->first();
    }

    // ------------------------------------------------- supported actions (UI)

    public function test_fee_collection_is_a_supported_approval_action(): void
    {
        $this->assertTrue(ApprovalRegistry::isSupported('fees', ApprovalRegistry::ACTION_FEE_COLLECTION));
        $this->assertSame('Fee Collection', ApprovalRegistry::labelFor('fees', 'fee_collection'));
        $this->assertSame('fee_collections.approve', ApprovalRegistry::permissionFor('fees', 'fee_collection'));
        $this->assertContains(
            'fees.fee_collection',
            array_map(fn (array $row): string => $row['module'].'.'.$row['action'], ApprovalRegistry::allSupported()),
        );
    }

    public function test_the_settings_screen_lists_the_new_action_for_an_administrator(): void
    {
        $college = $this->makeCollege('FCAUI');
        $admin = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.settings.manage']);

        $this->asCollege($college, $admin)
            ->get(route('approvals.settings.index'))
            ->assertOk()
            ->assertSee('fee_collection')
            ->assertSee('Fee Collection')
            // Module column renders ucfirst('fees') = "Fees".
            ->assertSee('Fees');
    }

    public function test_an_administrator_can_enable_and_disable_the_setting_through_the_existing_screen(): void
    {
        $college = $this->makeCollege('FCASET');
        $admin = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.settings.manage']);

        $payload = ['settings' => [[
            'module' => 'fees',
            'action' => 'fee_collection',
            'is_required' => 1,
        ]]];

        $this->asCollege($college, $admin)->put(route('approvals.settings.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('approval_settings', [
            'college_id' => $college->id,
            'module' => 'fees',
            'action' => 'fee_collection',
            'is_required' => true,
            'label' => 'Fee Collection',
        ]);

        $payload['settings'][0]['is_required'] = 0;
        $this->asCollege($college, $admin)->put(route('approvals.settings.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('approval_settings', [
            'college_id' => $college->id,
            'module' => 'fees',
            'action' => 'fee_collection',
            'is_required' => false,
        ]);

        // The change is audited like every other setting change.
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval_setting.updated']);
    }

    // ------------------------------------------------------- disabled = as before

    public function test_with_approval_disabled_a_collection_is_completed_immediately(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCOFF', approvalEnabled: false);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('fee-collections.index'));

        $payment = $this->paymentOf($college);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->collected_at);
        // No approval machinery was touched.
        $this->assertDatabaseCount('approval_requests', 0);

        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $ledger['paid']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);

        // Existing downstream behaviour is untouched: the receipt renders.
        $this->asCollege($college, $collector)
            ->get(route('receipts.show', $payment))
            ->assertOk();

        // The collection form does not warn about an approval that will never come.
        $this->asCollege($college, $collector)
            ->get(route('fee-collections.create'))
            ->assertOk()
            ->assertDontSee('Approval required.');
    }

    public function test_an_explicitly_disabled_setting_keeps_collections_immediate(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCOFF2', approvalEnabled: false);
        $this->enableApproval($college, false);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 5000))
            ->assertSessionHasNoErrors();

        $this->assertSame(FeePayment::STATUS_COMPLETED, $this->paymentOf($college)->status);
    }

    // -------------------------------------------------------- enabled = pending

    public function test_with_approval_enabled_a_collection_stays_pending_and_is_not_money(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCON', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('fee-collections.index'));

        $payment = $this->paymentOf($college);

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        // Not money yet: no collection stamp.
        $this->assertNull($payment->collected_at);
        $this->assertSame($collector->id, $payment->collected_by);
        // Server-generated number survives the workflow (it is the receipt number).
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{4}$/', $payment->payment_number);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.submitted_for_approval',
            'subject_id' => $payment->id,
        ]);

        $request = $this->approvalRequestOf($college, $payment);
        $this->assertNotNull($request, 'A pending collection must queue a central approval request.');
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame($collector->id, $request->requested_by);
        $this->assertSame(10000.0, (float) $request->payload['amount']);

        // Balances ignore it entirely.
        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(0.0, (float) $ledger['paid']);
        $this->assertSame(26500.0, (float) $ledger['outstanding']);

        // So do receipts: no receipt page, not listed as an issued receipt.
        $this->asCollege($college, $collector)->get(route('receipts.show', $payment))->assertForbidden();
        $this->asCollege($college, $collector)
            ->get(route('receipts.index'))
            ->assertOk()
            ->assertDontSee($payment->payment_number);

        // The collection register shows the workflow state honestly.
        $this->asCollege($college, $collector)
            ->get(route('fee-collections.index'))
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertSee('Pending approval')
            ->assertSee('Awaiting approval');

        // The accountant is warned before recording the next one.
        $this->asCollege($college, $collector)
            ->get(route('fee-collections.create'))
            ->assertOk()
            ->assertSee('Approval required.');
    }

    public function test_the_collection_report_only_counts_finalized_collections(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREP', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors();

        // Report 1 reads completed payments only, so a pending collection is not
        // "collected" money in any financial summary.
        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Finance\Services\FeeReportService::class)->collectionSummary([]),
        );
        $this->assertSame(0.0, (float) $summary['total']);
        $this->assertSame(0, (int) $summary['payments']);

        $payment = $this->paymentOf($college);
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $this->approvalRequestOf($college, $payment)))
            ->assertSessionHasNoErrors();

        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Finance\Services\FeeReportService::class)->collectionSummary([]),
        );
        $this->assertSame(10000.0, (float) $summary['total']);
        $this->assertSame(1, (int) $summary['payments']);
    }

    // ------------------------------------------- trusted authorities (no queue)

    /**
     * Super Admin: finalizes their own collection immediately, with no request
     * to approve and no "awaiting approval" warning on the form.
     */
    public function test_a_super_admin_collection_is_finalized_immediately(): void
    {
        ['college' => $college, 'assignment' => $assignment] = $this->fixture('FCSUP', approvalEnabled: true);
        $superAdmin = $this->makeSuperAdminUser($college);

        $this->assertTrue($superAdmin->isTrustedAuthority($college->id));
        $this->assertTrue($superAdmin->isSuperAdmin());

        $this->asCollege($college, $superAdmin)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('fee-collections.index'));

        $payment = $this->paymentOf($college);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->collected_at);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.collected',
            'subject_id' => $payment->id,
        ]);

        // It is money straight away.
        $this->assertSame(10000.0, (float) $this->ledgerOf($college, $assignment)['paid']);
        $this->asCollege($college, $superAdmin)->get(route('receipts.show', $payment))->assertOk();

        // …and the form does not claim an approval is needed.
        $this->asCollege($college, $superAdmin)
            ->get(route('fee-collections.create'))
            ->assertOk()
            ->assertDontSee('Approval required.');
    }

    /** College Admin: same trust, scoped to their own college. */
    public function test_a_college_admin_collection_is_finalized_immediately(): void
    {
        ['college' => $college, 'assignment' => $assignment] = $this->fixture('FCADM', approvalEnabled: true);
        $collegeAdmin = $this->makeCollegeAdminUser($college);

        $this->assertTrue($collegeAdmin->isCollegeAdmin($college->id));
        $this->assertTrue($collegeAdmin->isTrustedAuthority($college->id));
        $this->assertFalse($collegeAdmin->isSuperAdmin());

        $this->asCollege($college, $collegeAdmin)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 8000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertDatabaseCount('approval_requests', 0);
        $this->assertSame(8000.0, (float) $this->ledgerOf($college, $assignment)['paid']);
    }

    /**
     * The trust is per college: an administrator of college A collecting in
     * college B is an ORDINARY user there and must go through approval.
     */
    public function test_a_college_admin_role_from_another_college_is_not_trusted(): void
    {
        $homeCollege = $this->makeCollege('FCADMA');
        $otherCollege = $this->makeCollege('FCADMB');
        $this->enableApproval($homeCollege);
        $this->enableApproval($otherCollege);

        // Administrator of the HOME college, ordinary collector in the OTHER one.
        $adminRole = Role::query()->firstOrCreate(
            ['college_id' => $homeCollege->id, 'slug' => Role::COLLEGE_ADMIN_SLUG],
            ['name' => 'College Admin', 'is_system' => true, 'is_active' => true],
        );
        $adminRole->permissions()->sync(Permission::query()->pluck('id')->all());

        $user = User::create([
            'name' => 'Dual College User',
            'email' => 'dual-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($homeCollege->id, ['is_default' => true]);
        $user->colleges()->attach($otherCollege->id, ['is_default' => false]);
        $user->roles()->attach($adminRole->id, ['college_id' => $homeCollege->id]);

        $collectorRole = Role::create([
            'college_id' => $otherCollege->id,
            'name' => 'Finance Staff',
            'slug' => 'finance-staff-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        $collectorRole->permissions()->sync(Permission::query()->whereIn('slug', self::COLLECTOR)->pluck('id')->all());
        $user->roles()->attach($collectorRole->id, ['college_id' => $otherCollege->id]);

        // The trust is per college.
        $this->assertTrue($user->isTrustedAuthority($homeCollege->id));
        $this->assertFalse($user->isCollegeAdmin($otherCollege->id));
        $this->assertFalse($user->isTrustedAuthority($otherCollege->id));

        // A payable assignment IN the other college.
        $ctx = $this->makeFinanceContext($otherCollege, 'FCADMB');
        $structure = $this->makeFeeStructure($otherCollege, $ctx);
        $fixture = $this->makeFinanceEnrollment($otherCollege, $ctx, 'FCADMB');
        $assignment = $this->assignFeeStructure($otherCollege, $user, $fixture['enrollment'], $structure);

        $this->asCollege($otherCollege, $user)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 9000))
            ->assertSessionHasNoErrors();

        // Not trusted there, so the collection waits for that college's approver.
        $payment = $this->paymentOf($otherCollege);
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertNotNull($this->approvalRequestOf($otherCollege, $payment));
        $this->assertSame(0.0, (float) $this->ledgerOf($otherCollege, $assignment)['paid']);
    }

    /**
     * Holding the approval permission does NOT make a user a trusted authority:
     * they approve other people's collections, and their own still goes through
     * the workflow.
     */
    public function test_an_approver_who_is_not_an_administrator_still_needs_approval(): void
    {
        $college = $this->makeCollege('FCAPPR');
        $ctx = $this->makeFinanceContext($college, 'FCAPPR');
        $structure = $this->makeFeeStructure($college, $ctx);

        $accountant = $this->makeUserWithPermissions($college, array_merge(self::COLLECTOR, [
            'fee_collections.approve',
            'approvals.view',
            'approvals.approve',
        ]));
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FCAPPR');
        $assignment = $this->assignFeeStructure($college, $accountant, $fixture['enrollment'], $structure);
        $this->enableApproval($college);

        $this->assertFalse($accountant->isTrustedAuthority($college->id));

        $this->asCollege($college, $accountant)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 6000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertNotNull($this->approvalRequestOf($college, $payment));

        // …and they still cannot decide it themselves.
        $this->asCollege($college, $accountant)
            ->post(route('approvals.approve', $this->approvalRequestOf($college, $payment)))
            ->assertSessionHasErrors('decided_by');
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
    }

    /** A College Admin may decide a request they raised themselves. */
    public function test_a_college_admin_can_approve_their_own_request(): void
    {
        ['college' => $college, 'assignment' => $assignment] = $this->fixture('FCASELF', approvalEnabled: true);
        $collegeAdmin = $this->makeCollegeAdminUser($college);
        $payment = $this->queuePendingCollection($college, $collegeAdmin, $assignment, 4000);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $collegeAdmin)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Verified by admin'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertSame(4000.0, (float) $this->ledgerOf($college, $assignment)['paid']);

        // The self-decision is visible in the audit trail, not hidden.
        $log = \App\Models\AuditLog::withoutGlobalScopes()
            ->where('action', 'approval.approved')
            ->where('subject_id', $request->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue((bool) ($log->new_values['self_decision'] ?? false));
    }

    /** …and may reject their own request, keeping the row and never counting it. */
    public function test_a_college_admin_can_reject_their_own_request(): void
    {
        ['college' => $college, 'assignment' => $assignment] = $this->fixture('FCASELF2', approvalEnabled: true);
        $collegeAdmin = $this->makeCollegeAdminUser($college);
        $payment = $this->queuePendingCollection($college, $collegeAdmin, $assignment, 3000);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $collegeAdmin)
            ->post(route('approvals.reject', $request), ['decision_reason' => 'Entered twice'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(FeePayment::STATUS_REJECTED, $payment->fresh()->status);
        $this->assertStringContainsString('Entered twice', (string) $payment->fresh()->remarks);
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
    }

    /** Own pending requests appear in a trusted authority's Pending list. */
    public function test_own_pending_requests_are_listed_for_a_trusted_authority_only(): void
    {
        ['college' => $college, 'assignment' => $assignment] = $this->fixture('FCAPEND', approvalEnabled: true);
        $collegeAdmin = $this->makeCollegeAdminUser($college);
        $accountant = $this->makeUserWithPermissions($college, array_merge(self::COLLECTOR, ['approvals.view', 'fee_collections.approve']));

        $adminPayment = $this->queuePendingCollection($college, $collegeAdmin, $assignment, 1000);
        $accountantPayment = $this->queuePendingCollection($college, $accountant, $assignment, 1000);

        // The admin sees their own request (they may act on it)…
        $this->asCollege($college, $collegeAdmin)
            ->get(route('approvals.pending'))
            ->assertOk()
            ->assertSee($this->approvalRequestOf($college, $adminPayment)->reference_number);

        // …the accountant does not see theirs (they could not act on it).
        $response = $this->asCollege($college, $accountant)->get(route('approvals.pending'));
        $response->assertOk()->assertDontSee($this->approvalRequestOf($college, $accountantPayment)->reference_number);
    }

    // ------------------------------------------------------------- approval path

    public function test_an_authorized_approver_finalizes_the_collection(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCAPP', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request), ['decision_reason' => 'Cash verified'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $request->refresh();

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->status);
        $this->assertNotNull($payment->collected_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.approved',
            'subject_id' => $payment->id,
        ]);

        // Only now does the money appear in the ledger.
        $ledger = $this->ledgerOf($college, $assignment);
        $this->assertSame(10000.0, (float) $ledger['paid']);
        $this->assertSame(16500.0, (float) $ledger['outstanding']);

        // ...and the receipt exists.
        $this->asCollege($college, $collector)
            ->get(route('receipts.show', $payment))
            ->assertOk();
        $this->asCollege($college, $collector)
            ->get(route('receipts.index'))
            ->assertOk()
            ->assertSee($payment->payment_number);
    }

    public function test_rejection_keeps_the_row_and_never_counts_it_as_paid(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREJ', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $approver)
            ->post(route('approvals.reject', $request), ['decision_reason' => 'Cheque bounced'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $request->refresh();

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $this->assertSame(FeePayment::STATUS_REJECTED, $payment->status);
        // The row is preserved for the audit trail, with the reason attached.
        $this->assertNotNull($payment->payment_number);
        $this->assertStringContainsString('Cheque bounced', (string) $payment->remarks);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.rejected',
            'subject_id' => $payment->id,
        ]);

        // Never counted, never refundable, never receipted.
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
        $this->assertSame(0.0, $payment->refundableAmount());
        $this->asCollege($college, $collector)->get(route('receipts.show', $payment))->assertForbidden();

        // A rejected collection can never be approved afterwards.
        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request))
            ->assertSessionHasErrors();
        $this->assertSame(FeePayment::STATUS_REJECTED, $payment->fresh()->status);
    }

    public function test_rejection_requires_a_reason_and_leaves_the_collection_pending(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREJ2', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 4000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $approver)
            ->post(route('approvals.reject', $request), [])
            ->assertSessionHasErrors('decision_reason');

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
    }

    public function test_the_submitter_cannot_approve_their_own_collection(): void
    {
        // An accountant who ALSO holds the approval permission still cannot
        // self-approve: the existing self-approval rule wins.
        $college = $this->makeCollege('FCSELF');
        $collector = $this->makeUserWithPermissions($college, array_merge(self::COLLECTOR, ['fee_collections.approve']));
        $ctx = $this->makeFinanceContext($college, 'FCSELF');
        $structure = $this->makeFeeStructure($college, $ctx);
        $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FCSELF');
        $assignment = $this->assignFeeStructure($college, $collector, $fixture['enrollment'], $structure);
        $this->enableApproval($college);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 3000))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $collector)
            ->post(route('approvals.approve', $request))
            ->assertSessionHasErrors();

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_a_user_without_fee_collection_approval_cannot_decide(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FC403', approvalEnabled: true);
        $bystander = $this->makeUserWithPermissions($college, ['fee_collections.view', 'approvals.view']);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $bystander)->post(route('approvals.approve', $request))->assertForbidden();
        $this->asCollege($college, $bystander)->post(route('approvals.reject', $request), ['decision_reason' => 'no'])->assertForbidden();

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_another_college_cannot_decide_the_request(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCISO', approvalEnabled: true);
        $otherCollege = $this->makeCollege('FCISOB');
        $otherAdmin = $this->makeUserWithPermissions($otherCollege, self::APPROVER);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($otherCollege, $otherAdmin)
            ->post(route('approvals.approve', $request))
            ->assertNotFound();

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->fresh()->status);
    }

    public function test_the_submitter_can_withdraw_the_request_and_the_collection_is_reversed(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCWD', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);
        $request = $this->approvalRequestOf($college, $payment);

        $this->asCollege($college, $collector)
            ->post(route('approvals.cancel', $request))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELLED, $request->fresh()->status);
        // No stranded "pending approval" row: it is reversed through the audited
        // cancellation state and still never counted as paid.
        $this->assertSame(FeePayment::STATUS_CANCELLED, $payment->status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fee_payments.approval_withdrawn',
            'subject_id' => $payment->id,
        ]);
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
    }

    // ------------------------------------------------------- existing safeguards

    public function test_a_pending_collection_cannot_be_edited_cancelled_or_deleted(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCLOCK', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);

        $this->asCollege($college, $collector)->get(route('fee-collections.edit', $payment))->assertForbidden();

        $this->asCollege($college, $collector)
            ->put(route('fee-collections.update', $payment), [
                'payment_date' => '2026-08-20',
                'payment_mode' => FeePayment::MODE_CASH,
                'remarks' => 'Rewritten while awaiting approval',
            ])
            ->assertSessionHasErrors('payment_date');

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.cancel', $payment), ['cancellation_reason' => 'changed my mind'])
            ->assertSessionHasErrors('status');

        $this->asCollege($college, $collector)
            ->delete(route('fee-collections.destroy', $payment))
            ->assertSessionHasErrors();

        $payment->refresh();
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertSame('2026-08-15', $payment->payment_date->format('Y-m-d'));
        $this->assertSame('Second instalment', $payment->remarks);
    }

    public function test_a_pending_or_rejected_collection_cannot_be_refunded(): void
    {
        ['college' => $college, 'collector' => $collector, 'approver' => $approver, 'assignment' => $assignment]
            = $this->fixture('FCREF', approvalEnabled: true);
        $collector = $this->makeUserWithPermissions($college, array_merge(self::COLLECTOR, ['refunds.view', 'refunds.create']));

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500))
            ->assertSessionHasNoErrors();

        $payment = $this->paymentOf($college);

        $this->asCollege($college, $collector)
            ->post(route('refunds.store'), [
                'fee_payment_id' => $payment->id,
                'refund_date' => '2026-08-16',
                'amount' => 500,
                'reason' => 'Not collected yet',
            ])
            ->assertSessionHasErrors('fee_payment_id');

        $this->assertDatabaseCount('fee_refunds', 0);
        $this->assertSame(0.0, $payment->fresh()->refundableAmount());
    }

    public function test_a_duplicate_submission_is_refused(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCDUP', approvalEnabled: true);

        $payload = $this->collectPayload($assignment, 2500, ['reference_number' => 'UTR-DUP-1']);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $payload)
            ->assertSessionHasNoErrors();

        // The very same form posted twice: the idempotency token refuses it.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $payload)
            ->assertSessionHasErrors();

        // A fresh form with the same bank reference is refused as well.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 2500, ['reference_number' => 'UTR-DUP-1']))
            ->assertSessionHasErrors('reference_number');

        $this->assertSame(
            1,
            $this->withTenant($college, fn () => FeePayment::query()->count()),
        );
        $this->assertDatabaseCount('approval_requests', 1);
    }

    /**
     * The cap is `this amount + everything already awaiting approval <=
     * outstanding`.
     *
     * A pending collection is not counted as paid, so the ledger still reports
     * the full 26,500 as outstanding — but that money is already committed for
     * this student, so only the un-reserved part may be submitted again.
     */
    public function test_pending_collections_cannot_oversubscribe_the_balance(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCAP', approvalEnabled: true);

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 20000))
            ->assertSessionHasNoErrors();

        // Nothing is counted as collected, yet 20,000 of the 26,500 is reserved.
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);

        // 7,000 would breach the reserved balance even though the ledger still
        // shows 26,500 outstanding.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 7000))
            ->assertSessionHasErrors('amount');

        $this->assertSame(
            1,
            $this->withTenant($college, fn () => FeePayment::query()->count()),
        );

        // The un-reserved 6,500 is still allowed…
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 6500))
            ->assertSessionHasNoErrors();

        // …and once it is, the assignment is fully committed and nothing else
        // may be queued against it.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 1))
            ->assertSessionHasErrors('amount');

        $this->assertSame(
            2,
            $this->withTenant($college, fn () => FeePayment::query()->count()),
        );
    }

    /** The reservation is cumulative across several pending submissions. */
    public function test_several_pending_collections_cannot_exceed_the_balance_together(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCCUM', approvalEnabled: true);

        // 26,500 payable: 10,000 + 10,000 may be queued, the third 10,000 may not.
        foreach ([1, 2] as $attempt) {
            $this->asCollege($college, $collector)
                ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
                ->assertSessionHasNoErrors();
        }

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 10000))
            ->assertSessionHasErrors('amount');

        $this->assertSame(2, $this->withTenant($college, fn () => FeePayment::query()->count()));
        // Nothing is counted as collected while all of it waits.
        $this->assertSame(0.0, (float) $this->ledgerOf($college, $assignment)['paid']);
    }

    /**
     * A trusted authority's collection is finalized immediately, so it consumes
     * the balance through the normal ledger — and the reservation still protects
     * the remaining payable amount for everyone else.
     */
    public function test_a_finalized_admin_collection_reduces_what_may_be_queued(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCMIX', approvalEnabled: true);
        $collegeAdmin = $this->makeCollegeAdminUser($college);

        $this->asCollege($college, $collegeAdmin)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 20000))
            ->assertSessionHasNoErrors();

        $this->assertSame(20000.0, (float) $this->ledgerOf($college, $assignment)['paid']);

        // Only 6,500 is left: the accountant's 7,000 is refused, 6,500 is fine.
        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 7000))
            ->assertSessionHasErrors('amount');

        $this->asCollege($college, $collector)
            ->post(route('fee-collections.store'), $this->collectPayload($assignment, 6500))
            ->assertSessionHasNoErrors();
    }

    /**
     * The trusted-authority rule is the approval framework's, not a fee-only
     * special case: an ordinary user still cannot decide their own request in
     * another module, and a College Admin now can.
     */
    public function test_the_self_approval_rule_is_unchanged_for_ordinary_users_in_other_modules(): void
    {
        $college = $this->makeCollege('FCOTHER');
        $student = \App\Models\Student::create([
            'college_id' => $college->id,
            'student_number' => 'STU-OTHER-1',
            'first_name' => 'Other',
            'last_name' => 'Module',
            'status' => 'inactive',
            'registration_status' => \App\Models\Student::REGISTRATION_PENDING,
        ]);
        $staff = $this->makeUserWithPermissions($college, ['approvals.view', 'approvals.approve', 'students.update']);
        $collegeAdmin = $this->makeCollegeAdminUser($college);

        $makeRequest = fn (string $reference, User $requester): ApprovalRequest => ApprovalRequest::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'module' => 'student',
            'action' => 'registration_approval',
            'target_type' => 'student.registration_approval',
            'target_id' => $student->id,
            'reference_number' => $reference,
            'title' => 'Registration',
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_by' => $requester->id,
            'payload' => ['academic_year_id' => null],
        ]);

        // Same request, two requesters, two outcomes.
        $staffRequest = $makeRequest('APR-FCOTHER-1', $staff);
        $this->asCollege($college, $staff)
            ->post(route('approvals.approve', $staffRequest))
            ->assertSessionHasErrors('decided_by');
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $staffRequest->fresh()->status);

        $adminRequest = $makeRequest('APR-FCOTHER-2', $collegeAdmin);
        $this->asCollege($college, $collegeAdmin)
            ->post(route('approvals.approve', $adminRequest))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $adminRequest->fresh()->status);
    }

    public function test_the_same_gate_applies_to_transport_fee_collections(): void
    {
        $college = $this->makeCollege('FCTRN');
        $collector = $this->makeUserWithPermissions($college, [
            'transport_fees.view', 'transport_fees.create', 'transport_fees.update', 'transport_fees.delete',
            'fee_collections.view',
        ]);
        $approver = $this->makeUserWithPermissions($college, self::APPROVER);
        $this->enableApproval($college);

        $year = \App\Models\AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => 'AY-TRN',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'active',
        ]);
        $student = \App\Models\Student::create([
            'college_id' => $college->id,
            'student_number' => 'STU-TRN-1',
            'first_name' => 'Transport',
            'last_name' => 'Student',
            'status' => 'active',
        ]);
        $enrollment = \App\Models\StudentEnrollment::create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-TRN-1',
            'enrollment_date' => '2026-08-05',
            'status' => 'active',
        ]);
        // Transport route / stop models keep college_id and route_id out of
        // $fillable (they are stamped by their own services), so the fixtures are
        // built the way the Transport tests build them.
        $route = new TransportRoute(['name' => 'City', 'code' => 'R1', 'status' => 'active']);
        $route->college_id = $college->id;
        $route->save();

        $stop = new TransportStop(['name' => 'Stop 1', 'code' => 'S1', 'sequence' => 1, 'status' => 'active']);
        $stop->college_id = $college->id;
        $stop->route_id = $route->id;
        $stop->save();

        $transportAssignment = StudentTransportAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);
        $structure = TransportFeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'City Route Fee',
            'code' => 'TRF-1',
            'amount' => 1200.50,
            'effective_from' => '2026-07-01',
            'status' => 'active',
        ]);

        $this->asCollege($college, $collector)->post(route('transport-fees.store'), [
            'student_transport_assignment_id' => $transportAssignment->id,
            'transport_fee_structure_id' => $structure->id,
            'effective_from' => '2026-09-01',
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $fee = StudentTransportFeeAssignment::withoutGlobalScopes()->firstOrFail();

        $this->asCollege($college, $collector)->post(route('transport-fees.collect', $fee->id), [
            'payment_date' => '2026-09-05',
            'payment_mode' => FeePayment::MODE_UPI,
            'amount' => '600.25',
            'reference_number' => 'UTR-TRN-1',
            'submission_token' => Str::uuid()->toString(),
        ])->assertSessionHasNoErrors();

        // The shared payment row is pending, so the transport ledger is untouched.
        $payment = $this->withTenant($college, fn () => FeePayment::query()->latest('id')->firstOrFail());
        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertNotNull($payment->transport_fee_assignment_id);

        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Transport\Services\TransportFeeService::class)->summaryFor($fee->fresh()),
        );
        $this->assertSame(0.0, (float) $summary['net_collected']);

        // Approving it through the same central framework finalizes it.
        $request = $this->approvalRequestOf($college, $payment);
        $this->assertNotNull($request);

        $this->asCollege($college, $approver)
            ->post(route('approvals.approve', $request))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(FeePayment::STATUS_COMPLETED, $payment->fresh()->status);

        $summary = $this->withTenant(
            $college,
            fn () => app(\App\Domain\Transport\Services\TransportFeeService::class)->summaryFor($fee->fresh()),
        );
        $this->assertSame(600.25, (float) $summary['net_collected']);
    }

    public function test_a_collection_recorded_outside_a_request_still_uses_the_tenant_context(): void
    {
        ['college' => $college, 'collector' => $collector, 'assignment' => $assignment]
            = $this->fixture('FCSVC', approvalEnabled: true);

        $payment = $this->withTenant(
            $college,
            fn () => app(FeeCollectionService::class)->collect($assignment, [
                'payment_date' => '2026-08-15',
                'payment_mode' => FeePayment::MODE_CASH,
                'amount' => 1500,
            ], $collector),
        );

        $this->assertSame(FeePayment::STATUS_PENDING_APPROVAL, $payment->status);
        $this->assertSame($college->id, $payment->college_id);
        $this->assertNotNull($this->approvalRequestOf($college, $payment));
    }
}
