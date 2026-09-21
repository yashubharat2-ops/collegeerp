<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeePayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FeeReportService — read-only Finance / Fees reporting.
 *
 * Every figure is aggregated live from the existing transactional records
 * (student_fee_assignments, fee_payments, fee_concessions, fee_refunds); there
 * are no reporting tables and no second copy of any financial fact.
 *
 * Ledger-based reports (due summary, student fee report, program-wise fee
 * report) delegate to FeeDuesService, which owns the one definition of
 * "outstanding", so a report can never disagree with the Due / Outstanding
 * screen. Collection-based reports aggregate completed payments only —
 * cancelled/reversed collections are never counted as collected.
 *
 * Each method returns either a ready array of rows (already ordered
 * deterministically) or a paginator with a deterministic order, and every query
 * runs inside the active college's tenant scope.
 */
class FeeReportService
{
    public function __construct(private readonly FeeDuesService $dues)
    {
    }

    /**
     * Report 1 — Collection Summary: total collected, payment count and the
     * per-payment-mode breakdown. Optionally restricted to a date range.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: float, payments: int, modes: array<int, array{mode: string, payments: int, total: float}>}
     */
    public function collectionSummary(array $filters): array
    {
        $rows = $this->paymentsQuery($filters)
            ->reorder()
            ->getQuery()
            ->select(
                DB::raw('fee_payments.payment_mode as payment_mode'),
                DB::raw('COUNT(*) as payments'),
                DB::raw('COALESCE(SUM(fee_payments.amount), 0) as total'),
            )
            ->groupBy('fee_payments.payment_mode')
            ->orderBy('fee_payments.payment_mode')
            ->get();

        $modes = [];
        $total = 0.0;
        $payments = 0;

        foreach ($rows as $row) {
            $modes[] = [
                'mode' => (string) $row->payment_mode,
                'payments' => (int) $row->payments,
                'total' => FeeLedger::money($row->total),
            ];
            $total += (float) $row->total;
            $payments += (int) $row->payments;
        }

        return [
            'total' => FeeLedger::money($total),
            'payments' => $payments,
            'modes' => $modes,
        ];
    }

    /**
     * Report 5 — Payment Mode Report: the same breakdown, ordered by amount
     * collected (largest first, mode name as the tie-breaker) for readability.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{mode: string, payments: int, total: float}>
     */
    public function paymentModeSummary(array $filters): array
    {
        $rows = $this->collectionSummary($filters)['modes'];

        usort($rows, function (array $a, array $b): int {
            return [$b['total'], $a['mode']] <=> [$a['total'], $b['mode']];
        });

        return $rows;
    }

    /**
     * Report 2 — Due / Outstanding Summary (ledger totals for the filter set).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dueSummary(array $filters): array
    {
        return $this->dues->totals($filters);
    }

    /**
     * Report 3 — Student Fee Report: one row per assignment with its ledger
     * columns, deterministically paginated.
     *
     * @param  array<string, mixed>  $filters
     */
    public function studentFeeReport(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->dues->paginate($filters, $perPage);
    }

    /**
     * Report 4 — Program-wise Fee Report (ledger figures grouped by program).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function programWise(array $filters): array
    {
        return $this->dues->programWise($filters);
    }

    /**
     * Report 6 — Date-wise Collection Report (completed payments per day).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{date: string, payments: int, total: float}>
     */
    public function dateWiseCollection(array $filters): array
    {
        $rows = $this->paymentsQuery($filters)
            ->reorder()
            ->getQuery()
            ->select(
                DB::raw('fee_payments.payment_date as payment_date'),
                DB::raw('COUNT(*) as payments'),
                DB::raw('COALESCE(SUM(fee_payments.amount), 0) as total'),
            )
            ->groupBy('fee_payments.payment_date')
            ->orderByDesc('fee_payments.payment_date')
            ->get();

        $dates = [];

        foreach ($rows as $row) {
            $dates[] = [
                'date' => (string) $row->payment_date,
                'payments' => (int) $row->payments,
                'total' => FeeLedger::money($row->total),
            ];
        }

        return $dates;
    }

    /**
     * The tenant-scoped completed-payment query for a filter set.
     *
     * Cancelled collections, soft-deleted rows and foreign-college rows are
     * excluded by construction, so no report can count reversed money.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paymentsQuery(array $filters): Builder
    {
        return FeePayment::query()
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($filters['student_enrollment_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_payments.student_enrollment_id', $value))
            ->when($filters['fee_structure_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_payments.fee_structure_id', $value))
            ->when($filters['payment_mode'] ?? null, fn (Builder $q, $value) => $q->where('fee_payments.payment_mode', $value))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_payments.payment_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_payments.payment_date', '<=', $value))
            // Deterministic base order (reports that group apply their own).
            ->orderByDesc('fee_payments.id');
    }
}
