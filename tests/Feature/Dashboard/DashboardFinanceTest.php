<?php

namespace Tests\Feature\Dashboard;

use Tests\Feature\Finance\FeeStructureTestHelpers;
use Tests\TestCase;

class DashboardFinanceTest extends TestCase
{
    use FeeStructureTestHelpers;

    public function test_finance_metrics_reuse_the_finance_ledger_and_are_permission_and_tenant_scoped(): void
    {
        $college = $this->makeCollege('DASHFINA');
        $otherCollege = $this->makeCollege('DASHFINB');
        $financeViewer = $this->makeUserWithPermissions($college, [
            'finance_reports.view', 'student_fee_assignments.create', 'fee_collections.create', 'fee_collections.update',
        ]);
        $otherFinanceUser = $this->makeUserWithPermissions($otherCollege, [
            'student_fee_assignments.create', 'fee_collections.create',
        ]);
        $noFinanceAccess = $this->makeUserWithPermissions($college, []);

        $context = $this->makeFinanceContext($college, 'DASHFINA');
        $structure = $this->makeFeeStructure($college, $context);
        $enrollmentData = $this->makeFinanceEnrollment($college, $context, 'DASHFINA');
        $assignment = $this->assignFeeStructure($college, $financeViewer, $enrollmentData['enrollment'], $structure);
        $this->collectFee($college, $financeViewer, $assignment, 1000, ['payment_date' => '2026-08-15']);
        $cancelledPayment = $this->collectFee($college, $financeViewer, $assignment, 2000, ['payment_date' => '2026-08-16']);
        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeCollectionService::class)
            ->cancel($cancelledPayment, $financeViewer, 'Dashboard test reversal'));

        $otherContext = $this->makeFinanceContext($otherCollege, 'DASHFINB');
        $otherStructure = $this->makeFeeStructure($otherCollege, $otherContext);
        $otherEnrollmentData = $this->makeFinanceEnrollment($otherCollege, $otherContext, 'DASHFINB');
        $otherAssignment = $this->assignFeeStructure($otherCollege, $otherFinanceUser, $otherEnrollmentData['enrollment'], $otherStructure);
        $this->collectFee($otherCollege, $otherFinanceUser, $otherAssignment, 20000, ['payment_date' => '2026-08-15']);

        $response = $this->asCollege($college, $financeViewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-metric="fees-collected"', false)
            ->assertSee('data-dashboard-metric="pending-fees"', false)
            ->assertSee('Monthly fee collection')
            ->assertSee('Completed payments in the current academic year; refunds are reported separately.');
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/data-dashboard-metric="fees-collected">\s*1,000\.00\s*<\/p>/s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-dashboard-metric="fees-collected">\s*21,000\.00\s*<\/p>/s', $html);
        $this->assertMatchesRegularExpression('/data-dashboard-metric="pending-fees">\s*25,500\.00\s*<\/p>/s', $html);

        $this->asCollege($college, $noFinanceAccess)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-dashboard-metric="fees-collected"', false)
            ->assertDontSee('data-dashboard-metric="pending-fees"', false)
            ->assertDontSee('Monthly fee collection');
    }
}
