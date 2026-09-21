<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * FeeDuesService — the ONE place outstanding balances are calculated.
 *
 * There is deliberately no balance/dues table: every figure is derived live from
 * the assignment snapshot plus the transaction rows, using the shared formula in
 * FeeLedger:
 *
 *   assigned − applicable concessions − valid payments + valid refunds = outstanding
 *
 * "Valid" means: payments that are completed (cancelled/reversed and soft-deleted
 * rows are excluded), refunds that are neither rejected nor cancelled (and whose
 * payment is itself valid), and concessions that are neither rejected nor
 * cancelled.
 *
 * Implementation: the three transaction families are pre-aggregated into
 * subqueries joined to the assignment, so a page of the dues screen (or a whole
 * report) costs ONE query and no per-row lookups. The same arithmetic is also
 * expressed in SQL (CASE-based floor at zero, concessions capped at the assigned
 * amount) so the derived-status filter and the report totals agree with FeeLedger
 * to the paisa — a test pins both definitions together.
 *
 * The same query layer backs the Due / Outstanding screen, the Student Fee Report
 * and the Due Summary report, so no report can disagree with the screen. Nothing
 * here writes: a user can never edit an outstanding balance.
 */
class FeeDuesService
{
    /**
     * The tenant-scoped ledger query for a filter set.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters): Builder
    {
        $query = StudentFeeAssignment::query()
            ->select('student_fee_assignments.*')
            ->addSelect(DB::raw('COALESCE(ledger_concessions.total, 0) as concession_total'))
            ->addSelect(DB::raw('COALESCE(ledger_payments.total, 0) as paid_total'))
            ->addSelect(DB::raw('COALESCE(ledger_refunds.total, 0) as refunded_total'))
            ->leftJoinSub($this->concessionTotalsSub(), 'ledger_concessions', 'ledger_concessions.assignment_id', '=', 'student_fee_assignments.id')
            ->leftJoinSub($this->paymentTotalsSub(), 'ledger_payments', 'ledger_payments.assignment_id', '=', 'student_fee_assignments.id')
            ->leftJoinSub($this->refundTotalsSub(), 'ledger_refunds', 'ledger_refunds.assignment_id', '=', 'student_fee_assignments.id')
            ->with([
                'studentEnrollment.student',
                'studentEnrollment.academicYear',
                'studentEnrollment.program',
                'feeStructure',
            ])
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('program_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $sub) => $sub->where('student_id', $value)
            ))
            ->when($filters['student_enrollment_id'] ?? null, fn (Builder $q, $value) => $q->where('student_fee_assignments.student_enrollment_id', $value))
            ->when($filters['fee_structure_id'] ?? null, fn (Builder $q, $value) => $q->where('student_fee_assignments.fee_structure_id', $value))
            // A cancelled assignment carries no payable balance at all.
            ->when(
                in_array($filters['assignment_status'] ?? null, StudentFeeAssignment::STATUSES, true),
                fn (Builder $q) => $q->where('student_fee_assignments.status', $filters['assignment_status']),
                fn (Builder $q) => $q->whereIn('student_fee_assignments.status', StudentFeeAssignment::PAYABLE_STATUSES)
            )
            // Deterministic pagination order.
            ->orderByDesc('student_fee_assignments.id');

        return $this->applyLedgerStatus($query, $filters['status'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = $this->query($filters)->paginate($perPage)->withQueryString();

        $paginator->getCollection()->each(function (StudentFeeAssignment $assignment): void {
            $assignment->setAttribute('ledger', $this->summaryFromRow($assignment));
        });

        return $paginator;
    }

    /**
     * The ledger summary of a single assignment.
     *
     * The caller is responsible for holding the assignment row lock when the
     * value drives a write (a collection, concession or refund); the queries
     * below then see the freshest committed state inside that transaction.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(StudentFeeAssignment $assignment): array
    {
        return FeeLedger::summary(
            (float) $assignment->assigned_amount,
            $this->concessionSum($assignment),
            $this->paidSum($assignment),
            $this->refundedSum($assignment),
        );
    }

    /**
     * Money totals across the whole filtered set (not just the current page).
     *
     * @param  array<string, mixed>  $filters
     * @return array{assigned: float, concession: float, paid: float, refunded: float, net_collected: float, outstanding: float, assignments: int}
     */
    public function totals(array $filters): array
    {
        $row = $this->query($filters)
            ->reorder()
            ->getQuery()
            ->select(
                DB::raw('COUNT(student_fee_assignments.id) as assignments'),
                DB::raw('COALESCE(SUM(student_fee_assignments.assigned_amount), 0) as assigned'),
                DB::raw('COALESCE(SUM('.$this->concessionExpression().'), 0) as concession'),
                DB::raw('COALESCE(SUM(COALESCE(ledger_payments.total, 0)), 0) as paid'),
                DB::raw('COALESCE(SUM(COALESCE(ledger_refunds.total, 0)), 0) as refunded'),
                DB::raw('COALESCE(SUM('.$this->outstandingExpression().'), 0) as outstanding'),
            )
            ->first();

        $assigned = (float) ($row->assigned ?? 0);
        $paid = (float) ($row->paid ?? 0);
        $refunded = (float) ($row->refunded ?? 0);

        return [
            'assigned' => FeeLedger::money($assigned),
            'concession' => FeeLedger::money((float) ($row->concession ?? 0)),
            'paid' => FeeLedger::money($paid),
            'refunded' => FeeLedger::money($refunded),
            'net_collected' => FeeLedger::netCollected($paid, $refunded),
            'outstanding' => FeeLedger::money((float) ($row->outstanding ?? 0)),
            'assignments' => (int) ($row->assignments ?? 0),
        ];
    }

    /**
     * The ledger summary of a row produced by query(), reusing the joined totals
     * instead of issuing per-row queries.
     *
     * @return array<string, mixed>
     */
    public function summaryFromRow(StudentFeeAssignment $assignment): array
    {
        return FeeLedger::summary(
            (float) $assignment->assigned_amount,
            (float) ($assignment->concession_total ?? 0),
            (float) ($assignment->paid_total ?? 0),
            (float) ($assignment->refunded_total ?? 0),
        );
    }

    /**
     * Ledger figures grouped by program (Report 4 — Program-wise Fee Report).
     *
     * Grouping happens over the same joined ledger query the dues screen uses, so
     * the totals are the arithmetic sum of the per-assignment balances, with the
     * concession cap and the zero floor applied in SQL exactly as FeeLedger does
     * in PHP.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function programWise(array $filters): array
    {
        $rows = $this->query($filters)
            ->reorder()
            ->getQuery()
            ->join('student_enrollments', 'student_enrollments.id', '=', 'student_fee_assignments.student_enrollment_id')
            ->leftJoin('programs', 'programs.id', '=', 'student_enrollments.program_id')
            ->select(
                DB::raw('programs.id as program_id'),
                DB::raw('programs.name as program_name'),
                DB::raw('programs.code as program_code'),
                DB::raw('COUNT(student_fee_assignments.id) as assignments'),
                DB::raw('COALESCE(SUM(student_fee_assignments.assigned_amount), 0) as assigned'),
                DB::raw('COALESCE(SUM('.$this->concessionExpression().'), 0) as concession'),
                DB::raw('COALESCE(SUM(COALESCE(ledger_payments.total, 0)), 0) as paid'),
                DB::raw('COALESCE(SUM(COALESCE(ledger_refunds.total, 0)), 0) as refunded'),
                DB::raw('COALESCE(SUM('.$this->outstandingExpression().'), 0) as outstanding'),
            )
            ->groupBy('programs.id', 'programs.name', 'programs.code')
            ->orderBy('programs.name')
            ->orderBy('programs.id')
            ->get();

        $programs = [];

        foreach ($rows as $row) {
            $paid = (float) $row->paid;
            $refunded = (float) $row->refunded;

            $programs[] = [
                'program_id' => $row->program_id !== null ? (int) $row->program_id : null,
                'program_name' => $row->program_name !== null ? (string) $row->program_name : 'Unassigned program',
                'program_code' => $row->program_code !== null ? (string) $row->program_code : null,
                'assignments' => (int) $row->assignments,
                'assigned' => FeeLedger::money($row->assigned),
                'concession' => FeeLedger::money($row->concession),
                'paid' => FeeLedger::money($paid),
                'refunded' => FeeLedger::money($refunded),
                'net_collected' => FeeLedger::netCollected($paid, $refunded),
                'outstanding' => FeeLedger::money($row->outstanding),
            ];
        }

        return $programs;
    }

    /**
     * Ledger summaries for an arbitrary set of assignments in ONE query
     * (used by list screens that already hold tenant-scoped models).
     *
     * @param  iterable<StudentFeeAssignment>  $assignments
     * @return array<int, array<string, mixed>>  keyed by assignment id
     */
    public function ledgerFor(iterable $assignments): array
    {
        $ids = [];

        foreach ($assignments as $assignment) {
            $ids[] = $assignment->getKey();
        }

        if ($ids === []) {
            return [];
        }

        $rows = StudentFeeAssignment::query()
            ->whereIn('student_fee_assignments.id', $ids)
            ->select('student_fee_assignments.id as assignment_id', 'student_fee_assignments.assigned_amount')
            ->addSelect(DB::raw('COALESCE(ledger_concessions.total, 0) as concession_total'))
            ->addSelect(DB::raw('COALESCE(ledger_payments.total, 0) as paid_total'))
            ->addSelect(DB::raw('COALESCE(ledger_refunds.total, 0) as refunded_total'))
            ->leftJoinSub($this->concessionTotalsSub(), 'ledger_concessions', 'ledger_concessions.assignment_id', '=', 'student_fee_assignments.id')
            ->leftJoinSub($this->paymentTotalsSub(), 'ledger_payments', 'ledger_payments.assignment_id', '=', 'student_fee_assignments.id')
            ->leftJoinSub($this->refundTotalsSub(), 'ledger_refunds', 'ledger_refunds.assignment_id', '=', 'student_fee_assignments.id')
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            $summaries[(int) $row->assignment_id] = FeeLedger::summary(
                (float) $row->assigned_amount,
                (float) $row->concession_total,
                (float) $row->paid_total,
                (float) $row->refunded_total,
            );
        }

        return $summaries;
    }

    // -------------------------------------------------------- single sums

    private function concessionSum(StudentFeeAssignment $assignment): float
    {
        return (float) FeeConcession::withoutGlobalScopes()
            ->where('student_fee_assignment_id', $assignment->getKey())
            ->where('college_id', $assignment->college_id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', FeeConcession::INVALID_STATUSES)
            ->sum('amount');
    }

    private function paidSum(StudentFeeAssignment $assignment): float
    {
        return (float) FeePayment::withoutGlobalScopes()
            ->where('student_fee_assignment_id', $assignment->getKey())
            ->where('college_id', $assignment->college_id)
            ->whereNull('deleted_at')
            ->where('status', FeePayment::STATUS_COMPLETED)
            ->sum('amount');
    }

    private function refundedSum(StudentFeeAssignment $assignment): float
    {
        return (float) DB::table('fee_refunds')
            ->join('fee_payments', 'fee_payments.id', '=', 'fee_refunds.fee_payment_id')
            ->where('fee_payments.student_fee_assignment_id', $assignment->getKey())
            ->where('fee_payments.college_id', $assignment->college_id)
            ->where('fee_refunds.college_id', $assignment->college_id)
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)
            ->sum('fee_refunds.amount');
    }

    // ------------------------------------------------------------ SQL ledger

    /**
     * Restrict the query to one derived ledger status (paid / partial / due).
     *
     * The status is derived from the joined totals, so it is expressed as a
     * portable CASE-based predicate rather than a column comparison.
     */
    private function applyLedgerStatus(Builder $query, mixed $status): Builder
    {
        if (! in_array($status, FeeLedger::STATUSES, true)) {
            return $query;
        }

        $tolerance = FeeLedger::TOLERANCE;
        $outstanding = $this->outstandingExpression();
        $collected = '(COALESCE(ledger_payments.total, 0) - COALESCE(ledger_refunds.total, 0))';

        return match ($status) {
            FeeLedger::STATUS_PAID => $query->whereRaw("$outstanding <= ?", [$tolerance]),
            FeeLedger::STATUS_PARTIAL => $query->whereRaw("$outstanding > ?", [$tolerance])->whereRaw("$collected > ?", [$tolerance]),
            FeeLedger::STATUS_DUE => $query->whereRaw("$outstanding > ?", [$tolerance])->whereRaw("$collected <= ?", [$tolerance]),
        };
    }

    /** Concessions capped at the assigned amount (FeeLedger::effectiveConcessions). */
    private function concessionExpression(): string
    {
        return '(CASE WHEN COALESCE(ledger_concessions.total, 0) > student_fee_assignments.assigned_amount'
            .' THEN student_fee_assignments.assigned_amount ELSE COALESCE(ledger_concessions.total, 0) END)';
    }

    /** Outstanding amount, floored at zero (FeeLedger::outstanding). */
    private function outstandingExpression(): string
    {
        $raw = '(student_fee_assignments.assigned_amount'
            .' - '.$this->concessionExpression()
            .' - COALESCE(ledger_payments.total, 0)'
            .' + COALESCE(ledger_refunds.total, 0))';

        return "(CASE WHEN $raw < 0 THEN 0 ELSE $raw END)";
    }

    // ------------------------------------------------------------ subqueries

    /** Concessions that reduce the payable amount, summed per assignment. */
    private function concessionTotalsSub(): QueryBuilder
    {
        return DB::table('fee_concessions')
            ->when($this->tenantId() !== null, fn (QueryBuilder $q) => $q->where('fee_concessions.college_id', $this->tenantId()))
            ->whereNull('fee_concessions.deleted_at')
            ->whereNotIn('fee_concessions.status', FeeConcession::INVALID_STATUSES)
            ->groupBy('fee_concessions.student_fee_assignment_id')
            ->selectRaw('fee_concessions.student_fee_assignment_id as assignment_id, SUM(fee_concessions.amount) as total');
    }

    /** Completed collections, summed per assignment. */
    private function paymentTotalsSub(): QueryBuilder
    {
        return DB::table('fee_payments')
            ->when($this->tenantId() !== null, fn (QueryBuilder $q) => $q->where('fee_payments.college_id', $this->tenantId()))
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->groupBy('fee_payments.student_fee_assignment_id')
            ->selectRaw('fee_payments.student_fee_assignment_id as assignment_id, SUM(fee_payments.amount) as total');
    }

    /** Valid refunds (against valid payments), summed per assignment. */
    private function refundTotalsSub(): QueryBuilder
    {
        return DB::table('fee_refunds')
            ->join('fee_payments', 'fee_payments.id', '=', 'fee_refunds.fee_payment_id')
            ->when($this->tenantId() !== null, fn (QueryBuilder $q) => $q
                ->where('fee_refunds.college_id', $this->tenantId())
                ->where('fee_payments.college_id', $this->tenantId()))
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)
            ->groupBy('fee_payments.student_fee_assignment_id')
            ->selectRaw('fee_payments.student_fee_assignment_id as assignment_id, SUM(fee_refunds.amount) as total');
    }

    private function tenantId(): ?int
    {
        return app(TenantContext::class)->id();
    }
}
