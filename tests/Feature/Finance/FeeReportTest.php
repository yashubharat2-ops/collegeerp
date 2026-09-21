<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FeeReportService;
use App\Models\FeePayment;
use Tests\TestCase;

/**
 * Finance / Fees — Fee Reports.
 *
 * Every report is aggregated live from the transactional rows, so the tests pin
 * the arithmetic and the tenant/status filters rather than any stored figure:
 * cancelled collections never count as collected, ledger reports agree with the
 * Due screen, the mode and date breakdowns add up to the collection total, and
 * each report is deterministic and reachable only with fee_reports.view.
 */
class FeeReportTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * Two colleges' worth of data is not needed here; this builds one college
     * with a program (one enrollment) and a second enrollment in another year,
     * plus payments in two modes and a cancelled one.
     *
     * @return array{college: \App\Models\College, user: \App\Models\User, ctx: array, assignments: array}
     */
    private function reportFixture(string $code): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, [
            'fee_reports.view',
            'student_fee_assignments.create',
            'fee_collections.create',
            'fee_collections.view',
            'fee_collections.update',
        ]);
        $ctx = $this->makeFinanceContext($college, $code);
        $structure = $this->makeFeeStructure($college, $ctx);
        $first = $this->makeFinanceEnrollment($college, $ctx, $code.'A');
        $second = $this->makeFinanceEnrollment($college, $ctx, $code.'B');

        $assignmentA = $this->assignFeeStructure($college, $user, $first['enrollment'], $structure);
        $assignmentB = $this->assignFeeStructure($college, $user, $second['enrollment'], $structure);

        // 10,000 cash on 2026-08-10 + 5,000 UPI on 2026-08-11 for A,
        // 2,500 cash (later cancelled) and 4,000 cheque for B.
        $this->collectFee($college, $user, $assignmentA, 10000, ['payment_date' => '2026-08-10', 'payment_mode' => FeePayment::MODE_CASH]);
        $this->collectFee($college, $user, $assignmentA, 5000, ['payment_date' => '2026-08-11', 'payment_mode' => FeePayment::MODE_UPI]);
        $reversed = $this->collectFee($college, $user, $assignmentB, 2500, ['payment_date' => '2026-08-11', 'payment_mode' => FeePayment::MODE_CASH]);
        $this->collectFee($college, $user, $assignmentB, 4000, ['payment_date' => '2026-08-12', 'payment_mode' => FeePayment::MODE_CHEQUE, 'reference_number' => 'CHQ-RPT']);

        $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeCollectionService::class)->cancel($reversed, $user, 'Bounced'));

        return [
            'college' => $college,
            'user' => $user,
            'ctx' => $ctx,
            'assignments' => [$assignmentA, $assignmentB],
        ];
    }

    public function test_the_collection_summary_counts_completed_payments_only(): void
    {
        ['college' => $college] = $fixture = $this->reportFixture('FRPT1');

        $summary = $this->withTenant($college, fn () => app(FeeReportService::class)->collectionSummary([]));

        // 10,000 + 5,000 + 4,000; the cancelled 2,500 is excluded.
        $this->assertSame(19000.0, (float) $summary['total']);
        $this->assertSame(3, $summary['payments']);

        $modes = collect($summary['modes'])->keyBy('mode');
        $this->assertSame(10000.0, (float) $modes['cash']['total']);
        $this->assertSame(1, $modes['cash']['payments']);
        $this->assertSame(5000.0, (float) $modes['upi']['total']);
        $this->assertSame(4000.0, (float) $modes['cheque']['total']);
        $this->assertNull($modes->get('card'));

        $this->assertSame(19000.0, (float) collect($summary['modes'])->sum('total'));
        $this->assertNotNull($fixture);
    }

    public function test_the_collection_summary_honours_the_date_and_mode_filters(): void
    {
        ['college' => $college] = $this->reportFixture('FRPT2');

        $august10 = $this->withTenant($college, fn () => app(FeeReportService::class)->collectionSummary([
            'from' => '2026-08-10',
            'to' => '2026-08-10',
        ]));
        $this->assertSame(10000.0, (float) $august10['total']);
        $this->assertSame(1, $august10['payments']);

        $upi = $this->withTenant($college, fn () => app(FeeReportService::class)->collectionSummary(['payment_mode' => FeePayment::MODE_UPI]));
        $this->assertSame(5000.0, (float) $upi['total']);
        $this->assertSame(1, $upi['payments']);
    }

    public function test_the_due_summary_agrees_with_the_ledger_totals(): void
    {
        ['college' => $college] = $this->reportFixture('FRPT3');

        $summary = $this->withTenant($college, fn () => app(FeeReportService::class)->dueSummary([]));
        $totals = $this->withTenant($college, fn () => app(\App\Domain\Finance\Services\FeeDuesService::class)->totals([]));

        $this->assertSame($totals, $summary);
        $this->assertSame(2, $summary['assignments']);
        $this->assertSame(53000.0, (float) $summary['assigned']);
        $this->assertSame(19000.0, (float) $summary['paid']);
        $this->assertSame(34000.0, (float) $summary['outstanding']);
    }

    public function test_the_program_wise_report_groups_the_ledger_by_program(): void
    {
        ['college' => $college, 'ctx' => $ctx] = $this->reportFixture('FRPT4');

        $rows = $this->withTenant($college, fn () => app(FeeReportService::class)->programWise([]));

        $this->assertCount(1, $rows);
        $this->assertSame($ctx['prog']->id, $rows[0]['program_id']);
        $this->assertSame($ctx['prog']->name, $rows[0]['program_name']);
        $this->assertSame(2, $rows[0]['assignments']);
        $this->assertSame(53000.0, (float) $rows[0]['assigned']);
        $this->assertSame(19000.0, (float) $rows[0]['net_collected']);
        $this->assertSame(34000.0, (float) $rows[0]['outstanding']);
    }

    public function test_the_payment_mode_report_is_ordered_by_amount_collected(): void
    {
        ['college' => $college] = $this->reportFixture('FRPT5');

        $rows = $this->withTenant($college, fn () => app(FeeReportService::class)->paymentModeSummary([]));

        $this->assertSame(['cash', 'upi', 'cheque'], array_column($rows, 'mode'));
    }

    public function test_the_date_wise_report_groups_by_payment_date_newest_first(): void
    {
        ['college' => $college] = $this->reportFixture('FRPT6');

        $rows = $this->withTenant($college, fn () => app(FeeReportService::class)->dateWiseCollection([]));

        $this->assertSame(['2026-08-12', '2026-08-11', '2026-08-10'], array_column($rows, 'date'));
        $this->assertSame(4000.0, (float) $rows[0]['total']);
        $this->assertSame(5000.0, (float) $rows[1]['total']);
        $this->assertSame(10000.0, (float) $rows[2]['total']);
        $this->assertSame(19000.0, (float) collect($rows)->sum('total'));
    }

    public function test_the_student_fee_report_paginates_deterministically(): void
    {
        ['college' => $college, 'assignments' => $assignments] = $this->reportFixture('FRPT7');
        $ctx = $this->makeFinanceContext($college, 'FRPT7B');
        $structure = $this->makeFeeStructure($college, $ctx);
        $user = $this->makeUserWithPermissions($college, ['fee_reports.view', 'student_fee_assignments.create']);

        for ($i = 0; $i < 16; $i++) {
            $fixture = $this->makeFinanceEnrollment($college, $ctx, 'FRPT7-'.$i);
            $this->assignFeeStructure($college, $user, $fixture['enrollment'], $structure);
        }

        $page = $this->withTenant($college, fn () => app(FeeReportService::class)->studentFeeReport([], 10));
        $again = $this->withTenant($college, fn () => app(FeeReportService::class)->studentFeeReport([], 10));

        $this->assertSame(18, $page->total());
        $this->assertSame($page->getCollection()->pluck('id')->all(), $again->getCollection()->pluck('id')->all());
        $this->assertSame(10, $page->count());

        // The report is ordered newest-first, so the fixture's oldest assignment
        // (the one carrying the 10,000 + 5,000 collections) is on the second page.
        \Illuminate\Pagination\Paginator::currentPageResolver(fn () => 2);

        try {
            $secondPage = $this->withTenant($college, fn () => app(FeeReportService::class)->studentFeeReport([], 10));
        } finally {
            \Illuminate\Pagination\Paginator::currentPageResolver(fn () => 1);
        }

        $this->assertSame(8, $secondPage->count());

        // The rows carry their ledger summary.
        $row = $secondPage->getCollection()->firstWhere('id', $assignments[0]->id);
        $this->assertNotNull($row, 'The oldest assignment is on the second page.');
        $this->assertSame(15000.0, (float) $row->ledger['net_collected']);
    }

    public function test_the_reports_screen_renders_every_report(): void
    {
        ['college' => $college, 'user' => $user] = $this->reportFixture('FRPT8');

        foreach (array_keys(\App\Http\Controllers\FeeReportController::REPORTS) as $report) {
            $this->asCollege($college, $user)->get(route('fee-reports.index', ['report' => $report]))->assertOk();
        }

        $this->asCollege($college, $user)
            ->get(route('fee-reports.index'))
            ->assertOk()
            ->assertSee('Collection Summary')
            ->assertSee('19,000.00');
    }

    public function test_the_reports_are_tenant_scoped(): void
    {
        ['college' => $college] = $this->reportFixture('FRPT9');
        $other = $this->makeCollege('FRPT9X');
        $otherUser = $this->makeUserWithPermissions($other, ['student_fee_assignments.create', 'fee_collections.create']);
        $otherCtx = $this->makeFinanceContext($other, 'FRPT9X');
        $otherStructure = $this->makeFeeStructure($other, $otherCtx);
        $otherFixture = $this->makeFinanceEnrollment($other, $otherCtx, 'FRPT9X');
        $otherAssignment = $this->assignFeeStructure($other, $otherUser, $otherFixture['enrollment'], $otherStructure);
        $this->collectFee($other, $otherUser, $otherAssignment, 777);

        $summary = $this->withTenant($college, fn () => app(FeeReportService::class)->collectionSummary([]));
        $this->assertSame(19000.0, (float) $summary['total'], 'Another college’s collections must never leak in.');

        $dues = $this->withTenant($college, fn () => app(FeeReportService::class)->dueSummary([]));
        $this->assertSame(2, $dues['assignments']);

        $otherSummary = $this->withTenant($other, fn () => app(FeeReportService::class)->collectionSummary([]));
        $this->assertSame(777.0, (float) $otherSummary['total']);
    }

    public function test_the_screen_requires_the_report_permission(): void
    {
        ['college' => $college] = $this->reportFixture('FRPT10');
        $stranger = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $stranger)->get(route('fee-reports.index'))->assertForbidden();
    }

    public function test_an_unknown_report_falls_back_to_the_collection_summary(): void
    {
        ['college' => $college, 'user' => $user] = $this->reportFixture('FRPT11');

        $this->asCollege($college, $user)
            ->get(route('fee-reports.index', ['report' => 'nonsense']))
            ->assertOk()
            ->assertSee('Collection Summary')
            ->assertSee('19,000.00');
    }
}
