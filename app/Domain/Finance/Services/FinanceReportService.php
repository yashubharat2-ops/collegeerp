<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Hostel\Services\HostelFeeService;
use App\Domain\Transport\Services\TransportFeeService;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\HostelFeeAssignment;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportFeeAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorConcrete;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FinanceReportService — the READ side of the Finance Reports module.
 *
 * Eleven live reports over the EXISTING Finance / Fees records (fee structures,
 * fee categories, student fee assignments, fee collections, receipts, dues,
 * discounts / concessions, refunds) plus the existing Transport and Hostel fee
 * assignments, which store their charges in their own tables but record all
 * money in the SAME Finance fee_payments rows.
 *
 * There are no report tables, no snapshots and no second copy of any financial
 * fact. Where a report needs a balance, it asks the module's own service:
 *
 *   - student fee balances  → FeeDuesService (the ONE definition of outstanding,
 *     the same query layer the Due / Outstanding screen uses);
 *   - transport fee balances → TransportFeeService::ledgerFor();
 *   - hostel fee balances    → HostelFeeService::ledgerFor();
 *   - fee structure values   → FeeLedger::assignedFromItems() (the same sum the
 *     assignment snapshot is taken from);
 *   - money rounding / net collected / ledger status → FeeLedger.
 *
 * No arithmetic is re-implemented here: collections are COUNT/SUM aggregates of
 * completed fee_payments rows (the module's own definition of "collected"),
 * concessions and refunds are COUNT/SUM aggregates that exclude the module's
 * INVALID_STATUSES, and every balance comes from a service above.
 *
 * Rules honoured by every method:
 *
 *  - Tenant safety — every root query goes through a model carrying
 *    CollegeScope, and cross-table narrowing travels through the existing
 *    relationships, so a forged foreign filter id can only ever produce an
 *    empty report. Aggregates use toBase() (which keeps the global scopes) and
 *    subqueries are built from scoped builders.
 *  - Deterministic pagination — 20 rows per page with a unique tiebreak (the id)
 *    so pages never overlap or skip rows, and filters survive pagination.
 *  - Constant query counts per page — counts are SQL aggregates and relations
 *    are eager-loaded; nothing is fetched per row.
 *  - Read-only — nothing in this class writes.
 */
class FinanceReportService
{
    /** Rows per page, matching the other REPORTS modules. */
    public const PER_PAGE = 20;

    /** Fee type vocabulary (which Finance assignment a collection belongs to). */
    public const FEE_TYPES = ['tuition', 'transport', 'hostel'];

    /** Bounded batch used when a report derives ledger figures over a whole set. */
    private const CHUNK = 200;

    private const LEDGER_KEYS = ['assigned', 'concession', 'paid', 'refunded', 'net_collected', 'outstanding'];

    /** Relations rendered by the collection / receipt reports. */
    private const PAYMENT_RELATIONS = [
        'studentEnrollment.student',
        'studentEnrollment.academicYear',
        'studentEnrollment.program',
        'studentEnrollment.section',
        'studentFeeAssignment.feeStructure',
        'transportFeeAssignment.transportFeeStructure',
        'hostelFeeAssignment.feeStructure',
        'collector',
    ];

    public function __construct(
        private readonly FeeDuesService $dues,
        private readonly TransportFeeService $transportFees,
        private readonly HostelFeeService $hostelFees,
    ) {
    }

    /* ------------------------------------------------------------------ *
     * 1. Fee Collection Report
     * ------------------------------------------------------------------ */

    /**
     * One row per recorded collection (completed payments only — a cancelled /
     * reversed collection is never counted as collected), with the money totals
     * and the payment-mode breakdown of the same filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function collection(array $filters): array
    {
        $query = $this->collectionQuery($filters);

        $rows = (clone $query)
            ->reorder()
            ->with(self::PAYMENT_RELATIONS)
            ->orderByDesc('fee_payments.payment_date')
            ->orderByDesc('fee_payments.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return ['rows' => $rows, 'totals' => $this->paymentTotals($query)];
    }

    /* ------------------------------------------------------------------ *
     * 2. Fee Due / Outstanding Report
     * ------------------------------------------------------------------ */

    /**
     * The ledger report: one row per student fee assignment with its assigned /
     * concession / collected / refunded / outstanding columns from
     * FeeDuesService — the same query layer, and therefore the same numbers, as
     * the Due / Outstanding screen. The header totals are that service's own
     * filtered totals.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function dueOutstanding(array $filters): array
    {
        // FeeDuesService::paginate() is the same call the Due / Outstanding
        // screen makes — 20 rows a page here, same numbers there.
        $rows = $this->dues->paginate($filters, self::PER_PAGE);

        return ['rows' => $rows, 'totals' => $this->dues->totals($filters)];
    }

    /* ------------------------------------------------------------------ *
     * 3. Student Fee Ledger
     * ------------------------------------------------------------------ */

    /**
     * One row per student with their fee ledger position: the sum of their
     * filtered assignments' ledger figures (every balance comes from
     * FeeDuesService::ledgerFor(), so no report-specific balance arithmetic
     * exists). Rows are ordered by student name with the student id as the
     * deterministic tiebreak and paginated 20 per page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function studentLedger(array $filters): array
    {
        $query = $this->ledgerQuery($filters);

        /** @var array<int, array<string, mixed>> $students */
        $students = [];
        $totals = $this->emptyLedgerTotals();

        (clone $query)
            ->reorder()
            ->orderBy('student_fee_assignments.id')
            ->with(['studentEnrollment.student', 'studentEnrollment.program', 'studentEnrollment.section'])
            ->chunkById(self::CHUNK, function (Collection $chunk) use (&$students, &$totals): void {
                $ledger = $this->dues->ledgerFor($chunk);

                foreach ($chunk as $assignment) {
                    $summary = $ledger[$assignment->getKey()] ?? null;
                    $student = $assignment->studentEnrollment?->student;

                    if ($summary === null || $student === null) {
                        continue;
                    }

                    $id = (int) $student->getKey();

                    if (! isset($students[$id])) {
                        $students[$id] = [
                            'student' => $student,
                            'enrollment' => $assignment->studentEnrollment,
                            'assignments' => 0,
                            'assigned' => 0.0,
                            'concession' => 0.0,
                            'paid' => 0.0,
                            'refunded' => 0.0,
                            'net_collected' => 0.0,
                            'outstanding' => 0.0,
                        ];
                    }

                    foreach (self::LEDGER_KEYS as $key) {
                        $students[$id][$key] += $summary[$key];
                    }
                    $students[$id]['assignments']++;
                    $this->accumulate($totals, $summary);
                }
            });

        $rows = collect($students)
            ->map(function (array $row): array {
                foreach (self::LEDGER_KEYS as $key) {
                    $row[$key] = FeeLedger::money($row[$key]);
                }
                // The ledger status vocabulary of the module (paid / partial / due).
                $row['status'] = FeeLedger::status($row['assigned'], $row['concession'], $row['paid'], $row['refunded']);

                return $row;
            })
            ->sortBy(fn (array $row): array => [
                mb_strtolower(trim($row['student']->last_name.' '.$row['student']->first_name)),
                (int) $row['student']->getKey(),
            ])
            ->values();

        return ['rows' => $this->paginateCollection($rows), 'totals' => $this->finalizeLedgerTotals($totals)];
    }

    /* ------------------------------------------------------------------ *
     * 4. Fee Structure Report
     * ------------------------------------------------------------------ */

    /**
     * One row per fee structure with its fee-head count, deterministic head
     * total (the same ACTIVE-component sum the assignment snapshot is taken
     * from) and how many student fee assignments use it. The header aggregates
     * cover the whole filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function feeStructure(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = FeeStructure::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_structures.academic_year_id', $value))
            ->when($filters['academic_term_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_structures.academic_term_id', $value))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_structures.program_id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'program',
                fn (Builder $program) => $program->where('department_id', $value)
            ))
            ->when($filters['fee_category_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'allItems',
                fn (Builder $item) => $item->where('fee_category_id', $value)
            ))
            ->when(
                in_array($filters['status'] ?? null, FeeStructure::STATUSES, true),
                fn (Builder $q) => $q->where('fee_structures.status', $filters['status'])
            )
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('fee_structures.name', 'like', "%{$search}%")
                    ->orWhere('fee_structures.code', 'like', "%{$search}%");
            }))
            // Deterministic pagination order.
            ->orderByDesc('fee_structures.academic_year_id')
            ->orderBy('fee_structures.name')
            ->orderBy('fee_structures.id');

        $rows = (clone $query)
            ->with([
                'academicYear:id,name,code',
                'academicTerm:id,name,code',
                'program:id,name,code,department_id',
                'program.department:id,name',
                'allItems',
            ])
            ->withCount(['allItems as items_count', 'assignments as assignments_count'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->getCollection()->each(function (FeeStructure $structure): void {
            // The same sum of ACTIVE components the assignment snapshot uses.
            $structure->setAttribute('configured_total', FeeLedger::assignedFromItems($structure->allItems));
        });

        $structureIds = (clone $query)->reorder()->select('fee_structures.id')->toBase();

        $heads = FeeStructureItem::query()->whereIn('fee_structure_id', $structureIds);
        $headCount = (clone $heads)->count();
        $activeValue = (clone $heads)->where('status', FeeStructureItem::STATUS_ACTIVE)->sum('amount');
        $assignments = StudentFeeAssignment::query()->whereIn('fee_structure_id', $structureIds)->count();

        return [
            'rows' => $rows,
            'totals' => [
                'structures' => $rows->total(),
                'fee_heads' => (int) $headCount,
                'configured_value' => FeeLedger::money($activeValue),
                'assignments' => (int) $assignments,
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 5. Fee Assignment Report
     * ------------------------------------------------------------------ */

    /**
     * One row per student fee assignment (assignment date, status, structure and
     * snapshot amount) with the live ledger position of the same set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function assignment(array $filters): array
    {
        $query = $this->assignmentQuery($filters);

        $rows = (clone $query)
            ->with([
                'studentEnrollment.student',
                'studentEnrollment.academicYear',
                'studentEnrollment.program',
                'studentEnrollment.section',
                'feeStructure',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ledger = $this->dues->ledgerFor($rows->getCollection());

        $rows->getCollection()->each(function (StudentFeeAssignment $assignment) use ($ledger): void {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        });

        return ['rows' => $rows, 'totals' => $this->ledgerScan($query)];
    }

    /* ------------------------------------------------------------------ *
     * 6. Discount / Concession Report
     * ------------------------------------------------------------------ */

    /**
     * One row per concession / discount with the money it represents, plus the
     * recorded and the effective (rejected / cancelled excluded) totals of the
     * filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function concession(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = FeeConcession::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ))
            ->when($filters['section_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('section_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $value)
            ))
            ->when($filters['fee_structure_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment',
                fn (Builder $assignment) => $assignment->where('fee_structure_id', $value)
            ))
            ->when(in_array($filters['type'] ?? null, FeeConcession::TYPES, true), fn (Builder $q) => $q->where('fee_concessions.type', $filters['type']))
            ->when(in_array($filters['status'] ?? null, FeeConcession::STATUSES, true), fn (Builder $q) => $q->where('fee_concessions.status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_concessions.created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_concessions.created_at', '<=', $value))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('fee_concessions.reason', 'like', "%{$search}%")
                    ->orWhereHas('studentFeeAssignment.studentEnrollment.student', fn (Builder $student) => $student
                        ->where('student_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            }))
            // Deterministic pagination order.
            ->orderByDesc('fee_concessions.id');

        $rows = (clone $query)
            ->with([
                'studentFeeAssignment.studentEnrollment.student',
                'studentFeeAssignment.studentEnrollment.program',
                'studentFeeAssignment.studentEnrollment.academicYear',
                'studentFeeAssignment.feeStructure',
                'approver',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $statusCounts = (clone $query)
            ->reorder()
            ->toBase()
            ->select('fee_concessions.status', DB::raw('COUNT(*) as total'))
            ->groupBy('fee_concessions.status')
            ->pluck('total', 'status');

        return [
            'rows' => $rows,
            'totals' => [
                'concessions' => (int) (clone $query)->count(),
                'recorded' => FeeLedger::money((clone $query)->sum('fee_concessions.amount')),
                'effective' => FeeLedger::money(
                    (clone $query)->whereNotIn('fee_concessions.status', FeeConcession::INVALID_STATUSES)->sum('fee_concessions.amount')
                ),
                'by_status' => collect($statusCounts)->map(fn ($total) => (int) $total)->all(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 7. Refund Report
     * ------------------------------------------------------------------ */

    /**
     * One row per refund against an actual collection, plus the recorded and the
     * effective (rejected / cancelled excluded) refund totals of the filtered
     * set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function refund(array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = FeeRefund::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $value)
            ))
            ->when(in_array($filters['status'] ?? null, FeeRefund::STATUSES, true), fn (Builder $q) => $q->where('fee_refunds.status', $filters['status']))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_refunds.refund_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_refunds.refund_date', '<=', $value))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                $inner->where('fee_refunds.refund_number', 'like', "%{$search}%")
                    ->orWhereHas('payment', fn (Builder $payment) => $payment->where('payment_number', 'like', "%{$search}%"))
                    ->orWhereHas('payment.studentEnrollment.student', fn (Builder $student) => $student
                        ->where('student_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            }))
            // Deterministic pagination order.
            ->orderByDesc('fee_refunds.id');

        $rows = (clone $query)
            ->with([
                'payment.studentEnrollment.student',
                'payment.studentEnrollment.program',
                'payment.studentEnrollment.academicYear',
                'payment.feeStructure',
                'approver',
                'processor',
            ])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $statusCounts = (clone $query)
            ->reorder()
            ->toBase()
            ->select('fee_refunds.status', DB::raw('COUNT(*) as total'))
            ->groupBy('fee_refunds.status')
            ->pluck('total', 'status');

        return [
            'rows' => $rows,
            'totals' => [
                'refunds' => (int) (clone $query)->count(),
                'recorded' => FeeLedger::money((clone $query)->sum('fee_refunds.amount')),
                'effective' => FeeLedger::money(
                    (clone $query)->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)->sum('fee_refunds.amount')
                ),
                'by_status' => collect($statusCounts)->map(fn ($total) => (int) $total)->all(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 8. Receipt Report
     * ------------------------------------------------------------------ */

    /**
     * Issued receipts — the printable projection of completed collections. The
     * receipt number is the payment's own number, so searching by receipt number
     * is searching the payment number. Each row carries how much of that receipt
     * has been refunded (valid refunds only); the header totals cover the whole
     * filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function receipt(array $filters): array
    {
        $query = $this->collectionQuery($filters);

        $rows = (clone $query)
            ->reorder()
            ->with(self::PAYMENT_RELATIONS)
            ->withSum('validRefunds as refunded_amount', 'amount')
            ->orderByDesc('fee_payments.payment_date')
            ->orderByDesc('fee_payments.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totals = $this->paymentTotals($query);

        // Refunds that reduce the receipts of the same filtered set (valid only).
        $refunded = (float) FeeRefund::query()
            ->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)
            ->whereIn('fee_refunds.fee_payment_id', (clone $query)->reorder()->select('fee_payments.id')->toBase())
            ->sum('fee_refunds.amount');

        return [
            'rows' => $rows,
            'totals' => [
                'receipts' => $totals['payments'],
                'issued' => $totals['total'],
                'refunded' => FeeLedger::money($refunded),
                'net' => FeeLedger::netCollected($totals['total'], $refunded),
                'modes' => $totals['modes'],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 9. Transport Fee Report
     * ------------------------------------------------------------------ */

    /**
     * Transport fee assignments with their live ledger position. Every balance
     * is derived by TransportFeeService::ledgerFor() — the transport module's own
     * ledger derivation over the shared Finance payment rows — so this report can
     * never disagree with the Transport Fees screen.
     *
     * Cancelled fee assignments carry no payable balance (the same rule
     * FeeDuesService applies to student fee assignments), so they are excluded
     * unless the status filter selects them explicitly.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function transport(array $filters): array
    {
        $query = $this->transportFeeQuery($filters);

        $rows = (clone $query)
            ->with([
                'studentTransportAssignment.studentEnrollment.student',
                'studentTransportAssignment.studentEnrollment.program',
                'studentTransportAssignment.transportRoute',
                'studentTransportAssignment.transportStop',
                'transportFeeStructure',
                'academicYear',
            ])
            ->orderByDesc('student_transport_fee_assignments.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ledger = $this->transportFees->ledgerFor($rows->getCollection());

        $rows->getCollection()->each(function (StudentTransportFeeAssignment $assignment) use ($ledger): void {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        });

        return ['rows' => $rows, 'totals' => $this->transportLedgerTotals($query)];
    }

    /* ------------------------------------------------------------------ *
     * 10. Hostel Fee Report
     * ------------------------------------------------------------------ */

    /**
     * Hostel fee assignments with their live ledger position, derived by
     * HostelFeeService::ledgerFor() — the hostel module's own ledger derivation
     * over the shared Finance payment rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function hostel(array $filters): array
    {
        $query = $this->hostelFeeQuery($filters);

        $rows = (clone $query)
            ->with([
                'hostelAllocation.studentEnrollment.student',
                'hostelAllocation.studentEnrollment.program',
                'hostelAllocation.hostel',
                'hostelAllocation.room',
                'feeStructure',
                'academicYear',
            ])
            ->orderByDesc('hostel_fee_assignments.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ledger = $this->hostelFees->ledgerFor($rows->getCollection());

        $rows->getCollection()->each(function (HostelFeeAssignment $assignment) use ($ledger): void {
            $assignment->setAttribute('ledger', $ledger[$assignment->getKey()] ?? null);
        });

        return ['rows' => $rows, 'totals' => $this->hostelLedgerTotals($query)];
    }

    /* ------------------------------------------------------------------ *
     * 11. Financial Summary
     * ------------------------------------------------------------------ */

    /**
     * The financial position of the filtered set, aggregated from the existing
     * services and tables — never recomputed:
     *
     *   - student fees  → FeeDuesService::totals() (assigned, capped concessions,
     *     collected, refunded, net collected, outstanding);
     *   - collections   → COUNT/SUM of completed fee_payments rows, split by
     *     payment mode and by fee type (tuition / transport / hostel);
     *   - discounts and refunds → COUNT/SUM over the same records the concession
     *     and refund screens read, with the module's invalid statuses excluded
     *     from the effective figures;
     *   - transport and hostel fees → the same ledger derivations as their
     *     dedicated reports.
     *
     * The reconciliation block ties the student fee ledger together:
     * assigned − concessions − net collected = outstanding.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>}
     */
    public function summary(array $filters): array
    {
        $scope = [
            'academic_year_id' => $filters['academic_year_id'] ?? null,
            'program_id' => $filters['program_id'] ?? null,
        ];

        $studentFees = $this->dues->totals($scope);
        $payments = $this->paymentTotals($this->collectionQuery($scope));
        $byType = $this->paymentsByFeeType($scope);
        $concessions = $this->concessionTotals($scope);
        $refunds = $this->refundTotals($scope);
        $transport = $this->transportLedgerTotals($this->transportFeeQuery($scope));
        $hostel = $this->hostelLedgerTotals($this->hostelFeeQuery($scope));

        $netCollected = FeeLedger::netCollected($payments['total'], $refunds['effective']);
        $outstandingTotal = FeeLedger::money($studentFees['outstanding'] + $transport['outstanding'] + $hostel['outstanding']);
        $difference = FeeLedger::money(
            $studentFees['assigned'] - $studentFees['concession'] - $studentFees['net_collected'] - $studentFees['outstanding']
        );

        return [
            'summary' => [
                'scope' => $scope,
                'student_fees' => $studentFees,
                'transport_fees' => $transport,
                'hostel_fees' => $hostel,
                'collections' => [
                    'payments' => $payments['payments'],
                    'total' => $payments['total'],
                    'modes' => $payments['modes'],
                    'by_type' => $byType,
                ],
                'concessions' => $concessions,
                'refunds' => $refunds,
                'fee_value_total' => FeeLedger::money($studentFees['assigned'] + $transport['assigned'] + $hostel['assigned']),
                'net_collected' => $netCollected,
                'outstanding_total' => $outstandingTotal,
                'reconciliation' => [
                    'assigned' => $studentFees['assigned'],
                    'concession' => $studentFees['concession'],
                    'net_collected' => $studentFees['net_collected'],
                    'outstanding' => $studentFees['outstanding'],
                    'difference' => $difference,
                    'balanced' => abs($difference) <= FeeLedger::TOLERANCE,
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Shared query builders
     * ------------------------------------------------------------------ */

    /**
     * Completed collections of the active college.
     *
     * Filters run through the payment's OWN enrollment stamp (exactly like the
     * Receipts screen), so tuition, transport and hostel collections filter
     * identically — transport and hostel payments carry no
     * student_fee_assignment_id.
     *
     * @param  array<string, mixed>  $filters
     */
    private function collectionQuery(array $filters): Builder
    {
        return FeePayment::query()
            // A cancelled / reversed collection is never counted or listed.
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ))
            ->when($filters['section_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('section_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $value)
            ))
            ->when($filters['student_enrollment_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_payments.student_enrollment_id', $value))
            ->when($filters['fee_structure_id'] ?? null, fn (Builder $q, $value) => $q->where('fee_payments.fee_structure_id', $value))
            ->when($filters['payment_mode'] ?? null, fn (Builder $q, $value) => $q->where('fee_payments.payment_mode', $value))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_payments.payment_date', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('fee_payments.payment_date', '<=', $value))
            ->when(
                in_array($filters['fee_type'] ?? null, self::FEE_TYPES, true),
                fn (Builder $q) => $this->constrainFeeType($q, (string) $filters['fee_type'])
            )
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn (Builder $q) => $this->searchPayments($q, trim((string) $filters['search'])))
            // Deterministic base order (row reports re-order for display).
            ->orderByDesc('fee_payments.id');
    }

    /**
     * The ONE student fee ledger query (FeeDuesService), narrowed by the
     * enrollment filters the ledger report adds on top (student search and
     * section). Row selection only — every figure still comes from the service.
     *
     * @param  array<string, mixed>  $filters
     */
    private function ledgerQuery(array $filters): Builder
    {
        return $this->dues->query($filters)
            ->when($filters['section_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('section_id', $value)
            ))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn (Builder $q) => $this->searchStudents($q, trim((string) $filters['search'])));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assignmentQuery(array $filters): Builder
    {
        return StudentFeeAssignment::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ))
            ->when($filters['section_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('section_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $value)
            ))
            ->when($filters['fee_structure_id'] ?? null, fn (Builder $q, $value) => $q->where('student_fee_assignments.fee_structure_id', $value))
            ->when(
                in_array($filters['status'] ?? null, StudentFeeAssignment::STATUSES, true),
                fn (Builder $q) => $q->where('student_fee_assignments.status', $filters['status'])
            )
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('student_fee_assignments.assigned_at', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('student_fee_assignments.assigned_at', '<=', $value))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn (Builder $q) => $this->searchStudents($q, trim((string) $filters['search'])))
            // Deterministic pagination order.
            ->orderByDesc('student_fee_assignments.id');
    }

    /**
     * Payable transport fee assignments of the active college. Cancelled charges
     * are excluded unless selected explicitly (the module's PAYABLE_STATUSES
     * rule); route / stop narrowing travels through the existing transport
     * assignment relationship.
     *
     * @param  array<string, mixed>  $filters
     */
    private function transportFeeQuery(array $filters): Builder
    {
        return StudentTransportFeeAssignment::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->where('student_transport_fee_assignments.academic_year_id', $value))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentTransportAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentTransportAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ))
            ->when($filters['route_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentTransportAssignment',
                fn (Builder $transport) => $transport->where('transport_route_id', $value)
            ))
            ->when($filters['stop_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentTransportAssignment',
                fn (Builder $transport) => $transport->where('transport_stop_id', $value)
            ))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->whereDate('student_transport_fee_assignments.effective_from', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('student_transport_fee_assignments.effective_from', '<=', $value))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn (Builder $q) => $q->whereHas(
                'studentTransportAssignment.studentEnrollment.student',
                fn (Builder $student) => $this->searchStudentName($student, trim((string) $filters['search']))
            ))
            ->when(
                in_array($filters['status'] ?? null, StudentTransportFeeAssignment::STATUSES, true),
                fn (Builder $q) => $q->where('student_transport_fee_assignments.status', $filters['status']),
                fn (Builder $q) => $q->whereIn('student_transport_fee_assignments.status', StudentTransportFeeAssignment::PAYABLE_STATUSES)
            )
            // Deterministic pagination order.
            ->orderByDesc('student_transport_fee_assignments.id');
    }

    /**
     * Payable hostel fee assignments of the active college, narrowed through the
     * existing hostel allocation relationship. The academic-term filter mirrors
     * the hostel module's own term constraint (the enrollment's term-wise
     * academic records).
     *
     * @param  array<string, mixed>  $filters
     */
    private function hostelFeeQuery(array $filters): Builder
    {
        return HostelFeeAssignment::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->where('hostel_fee_assignments.academic_year_id', $value))
            ->when($filters['academic_term_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'hostelAllocation.studentEnrollment.academicRecords',
                fn (Builder $record) => $record->where('student_academic_records.academic_term_id', $value)
            ))
            ->when($filters['hostel_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'hostelAllocation',
                fn (Builder $allocation) => $allocation->where('hostel_id', $value)
            ))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'hostelAllocation.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('student_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'hostelAllocation.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ))
            ->when($filters['from'] ?? null, fn (Builder $q, $value) => $q->where(function (Builder $inner) use ($value): void {
                $inner->whereNull('hostel_fee_assignments.effective_until')
                    ->orWhereDate('hostel_fee_assignments.effective_until', '>=', $value);
            }))
            ->when($filters['to'] ?? null, fn (Builder $q, $value) => $q->whereDate('hostel_fee_assignments.effective_from', '<=', $value))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn (Builder $q) => $q->whereHas(
                'hostelAllocation.studentEnrollment.student',
                fn (Builder $student) => $this->searchStudentName($student, trim((string) $filters['search']))
            ))
            ->when(
                in_array($filters['status'] ?? null, HostelFeeAssignment::STATUSES, true),
                fn (Builder $q) => $q->where('hostel_fee_assignments.status', $filters['status']),
                fn (Builder $q) => $q->whereIn('hostel_fee_assignments.status', HostelFeeAssignment::PAYABLE_STATUSES)
            )
            // Deterministic pagination order.
            ->orderByDesc('hostel_fee_assignments.id');
    }

    /* ------------------------------------------------------------------ *
     * Shared aggregates
     * ------------------------------------------------------------------ */

    /**
     * Collection count, money total and payment-mode breakdown of the filtered
     * set (the same COUNT / SUM / GROUP BY the Fee Reports collection summary
     * uses, over the completed-payment base built above).
     *
     * @return array{payments: int, total: float, modes: array<int, array{mode: string, payments: int, total: float}>}
     */
    private function paymentTotals(Builder $query): array
    {
        $rows = (clone $query)
            ->reorder()
            ->toBase()
            ->select(
                DB::raw('fee_payments.payment_mode as payment_mode'),
                DB::raw('COUNT(*) as payments'),
                DB::raw('COALESCE(SUM(fee_payments.amount), 0) as total'),
            )
            ->groupBy('fee_payments.payment_mode')
            ->orderBy('fee_payments.payment_mode')
            ->get();

        $modes = [];
        $payments = 0;
        $total = 0.0;

        foreach ($rows as $row) {
            $modes[] = [
                'mode' => (string) $row->payment_mode,
                'payments' => (int) $row->payments,
                'total' => FeeLedger::money($row->total),
            ];
            $payments += (int) $row->payments;
            $total += (float) $row->total;
        }

        return ['payments' => $payments, 'total' => FeeLedger::money($total), 'modes' => $modes];
    }

    /**
     * Collections grouped by fee type (tuition / transport / hostel) — the CASE
     * reads the shared payment row, so the three buckets always add up to the
     * collection total.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{fee_type: string, payments: int, total: float}>
     */
    private function paymentsByFeeType(array $filters): array
    {
        $rows = $this->collectionQuery($filters)
            ->reorder()
            ->toBase()
            ->select(DB::raw(
                "CASE WHEN fee_payments.student_fee_assignment_id IS NOT NULL THEN 'tuition'"
                ." WHEN fee_payments.transport_fee_assignment_id IS NOT NULL THEN 'transport'"
                ." WHEN fee_payments.hostel_fee_assignment_id IS NOT NULL THEN 'hostel'"
                ." ELSE 'unclassified' END as fee_type"
            ))
            ->addSelect(DB::raw('COUNT(*) as payments'), DB::raw('COALESCE(SUM(fee_payments.amount), 0) as total'))
            ->groupByRaw('fee_type')
            ->orderByRaw('fee_type')
            ->get();

        return array_map(fn ($row): array => [
            'fee_type' => (string) $row->fee_type,
            'payments' => (int) $row->payments,
            'total' => FeeLedger::money($row->total),
        ], $rows->all());
    }

    /**
     * Recorded / effective concession totals of the filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{concessions: int, recorded: float, effective: float}
     */
    private function concessionTotals(array $filters): array
    {
        $query = FeeConcession::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'studentFeeAssignment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ));

        return [
            'concessions' => (int) (clone $query)->count(),
            'recorded' => FeeLedger::money((clone $query)->sum('fee_concessions.amount')),
            'effective' => FeeLedger::money(
                (clone $query)->whereNotIn('fee_concessions.status', FeeConcession::INVALID_STATUSES)->sum('fee_concessions.amount')
            ),
        ];
    }

    /**
     * Recorded / effective refund totals of the filtered set.
     *
     * @param  array<string, mixed>  $filters
     * @return array{refunds: int, recorded: float, effective: float}
     */
    private function refundTotals(array $filters): array
    {
        $query = FeeRefund::query()
            ->when($filters['academic_year_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('academic_year_id', $value)
            ))
            ->when($filters['program_id'] ?? null, fn (Builder $q, $value) => $q->whereHas(
                'payment.studentEnrollment',
                fn (Builder $enrollment) => $enrollment->where('program_id', $value)
            ));

        return [
            'refunds' => (int) (clone $query)->count(),
            'recorded' => FeeLedger::money((clone $query)->sum('fee_refunds.amount')),
            'effective' => FeeLedger::money(
                (clone $query)->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)->sum('fee_refunds.amount')
            ),
        ];
    }

    /**
     * Student fee ledger totals over the whole filtered set, derived per
     * assignment by FeeDuesService and summed — the same figures the ledger
     * report rows add up to.
     *
     * @return array<string, mixed>
     */
    private function ledgerScan(Builder $query): array
    {
        $totals = $this->emptyLedgerTotals();

        (clone $query)
            ->reorder()
            ->orderBy('student_fee_assignments.id')
            ->chunkById(self::CHUNK, function (Collection $chunk) use (&$totals): void {
                $ledger = $this->dues->ledgerFor($chunk);

                foreach ($chunk as $assignment) {
                    $summary = $ledger[$assignment->getKey()] ?? null;

                    if ($summary !== null) {
                        $this->accumulate($totals, $summary);
                    }
                }
            });

        return $this->finalizeLedgerTotals($totals);
    }

    /**
     * Transport fee ledger totals over the whole filtered set, derived by the
     * existing TransportFeeService ledger.
     *
     * @return array<string, mixed>
     */
    private function transportLedgerTotals(Builder $query): array
    {
        $totals = $this->emptyLedgerTotals();

        (clone $query)
            ->reorder()
            ->orderBy('student_transport_fee_assignments.id')
            ->chunkById(self::CHUNK, function (Collection $chunk) use (&$totals): void {
                $ledger = $this->transportFees->ledgerFor($chunk);

                foreach ($chunk as $assignment) {
                    $summary = $ledger[$assignment->getKey()] ?? null;

                    if ($summary !== null) {
                        $this->accumulate($totals, $summary);
                    }
                }
            });

        return $this->finalizeLedgerTotals($totals);
    }

    /**
     * Hostel fee ledger totals over the whole filtered set, derived by the
     * existing HostelFeeService ledger.
     *
     * @return array<string, mixed>
     */
    private function hostelLedgerTotals(Builder $query): array
    {
        $totals = $this->emptyLedgerTotals();

        (clone $query)
            ->reorder()
            ->orderBy('hostel_fee_assignments.id')
            ->chunkById(self::CHUNK, function (Collection $chunk) use (&$totals): void {
                $ledger = $this->hostelFees->ledgerFor($chunk);

                foreach ($chunk as $assignment) {
                    $summary = $ledger[$assignment->getKey()] ?? null;

                    if ($summary !== null) {
                        $this->accumulate($totals, $summary);
                    }
                }
            });

        return $this->finalizeLedgerTotals($totals);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Paginate an already-aggregated collection (the per-student ledger), keeping
     * the query string (filters + report) on every page link.
     */
    private function paginateCollection(Collection $rows): LengthAwarePaginator
    {
        $page = LengthAwarePaginatorConcrete::resolveCurrentPage();

        return new LengthAwarePaginatorConcrete(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => request()->query()],
        );
    }

    /**
     * A collection of one assignment group: assignments count + the six ledger
     * figures + how many of them still carry a balance.
     *
     * @return array<string, mixed>
     */
    private function emptyLedgerTotals(): array
    {
        return [
            'assignments' => 0,
            'assigned' => 0.0,
            'concession' => 0.0,
            'paid' => 0.0,
            'refunded' => 0.0,
            'net_collected' => 0.0,
            'outstanding' => 0.0,
            'outstanding_assignments' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $summary
     */
    private function accumulate(array &$totals, array $summary): void
    {
        $totals['assignments']++;

        foreach (self::LEDGER_KEYS as $key) {
            $totals[$key] += (float) $summary[$key];
        }

        if (((float) $summary['outstanding']) > FeeLedger::TOLERANCE) {
            $totals['outstanding_assignments']++;
        }
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function finalizeLedgerTotals(array $totals): array
    {
        foreach (self::LEDGER_KEYS as $key) {
            $totals[$key] = FeeLedger::money($totals[$key]);
        }

        // The module's ledger status vocabulary, derived from the aggregate.
        $totals['status'] = $totals['assignments'] === 0
            ? null
            : FeeLedger::status($totals['assigned'], $totals['concession'], $totals['paid'], $totals['refunded']);

        return $totals;
    }

    private function constrainFeeType(Builder $query, string $feeType): Builder
    {
        return match ($feeType) {
            'transport' => $query->whereNotNull('fee_payments.transport_fee_assignment_id'),
            'hostel' => $query->whereNotNull('fee_payments.hostel_fee_assignment_id'),
            default => $query->whereNotNull('fee_payments.student_fee_assignment_id'),
        };
    }

    private function searchPayments(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $inner) use ($search): void {
            $inner->where('fee_payments.payment_number', 'like', "%{$search}%")
                ->orWhere('fee_payments.reference_number', 'like', "%{$search}%")
                ->orWhereHas('studentEnrollment.student', fn (Builder $student) => $this->searchStudentName($student, $search));
        });
    }

    private function searchStudents(Builder $query, string $search): Builder
    {
        return $query->whereHas('studentEnrollment.student', fn (Builder $student) => $this->searchStudentName($student, $search));
    }

    private function searchStudentName(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $inner) use ($search): void {
            $inner->where('student_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%");
        });
    }
}
