<?php

namespace App\Domain\Transport\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\{College, FeePayment, FeeRefund, StudentTransportAssignment, StudentTransportFeeAssignment, TransportFeeStructure, User};
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TransportFeeService — transport fee structures (pricing master) and the
 * student transport fee assignments charged against existing transport
 * assignments.
 *
 * THIS SERVICE STORES NO MONEY. Collections are recorded through the existing
 * Finance module (FeePayment rows, one payment number series, receipts as
 * projections), and every balance here is DERIVED live from those same rows
 * with the shared FeeLedger arithmetic — there is no parallel payment, receipt
 * or ledger system:
 *
 *   outstanding = assigned amount − valid payments + valid refunds
 *
 * (transport fees have no concessions; the concession term is simply zero).
 *
 * What the assignment path guarantees, inside one transaction on locked rows:
 *
 *  - the transport assignment and the fee structure belong to the ACTIVE
 *    college and are contextually compatible (structure year/period and its
 *    optional route/stop narrowing match the transport assignment);
 *  - the transport assignment is valid (active — a cancelled assignment is
 *    never charged);
 *  - `amount` is SNAPSHOTTED SERVER-SIDE from the fee structure and is
 *    immutable afterwards (a browser-supplied amount is never read, and later
 *    edits to the structure never re-price history);
 *  - two ACTIVE fee assignments for the same transport assignment can never
 *    have OVERLAPPING applicability periods (exact duplicates are additionally
 *    rejected by a partial unique index); historical rows never block;
 *  - tenant and actor fields are always server-controlled.
 */
class TransportFeeService
{
    private const AUDITED_STRUCTURE = [
        'academic_year_id', 'transport_route_id', 'transport_stop_id', 'name', 'code',
        'amount', 'effective_from', 'effective_until', 'status', 'remarks',
    ];

    private const AUDITED_ASSIGNMENT = [
        'student_transport_assignment_id', 'transport_fee_structure_id', 'academic_year_id',
        'amount', 'effective_from', 'effective_until', 'status', 'remarks',
    ];

    private const OVERLAP_MESSAGE = 'This transport assignment already has an active transport fee assignment for the selected period.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    // ------------------------------------------------ transport fee structures

    /**
     * @param  array{academic_year_id: int, transport_route_id?: int|null, transport_stop_id?: int|null, name: string, code: string, amount: mixed, effective_from: string, effective_until?: string|null, status: string, remarks?: string|null}  $data
     */
    public function saveStructure(TransportFeeStructure $structure, array $data, User $actor): TransportFeeStructure
    {
        return DB::transaction(function () use ($structure, $data, $actor): TransportFeeStructure {
            $college = $this->lockCollege();
            $creating = ! $structure->exists;
            if (! $creating) {
                $this->assertTenant($structure, $college->id);
                $structure = TransportFeeStructure::withoutGlobalScopes()->lockForUpdate()->findOrFail($structure->id);
            }

            $year = $this->requireRecord(\App\Models\AcademicYear::class, (int) $data['academic_year_id'], $college->id, 'academic_year_id');

            $routeId = ($data['transport_route_id'] ?? null) ?: null;
            $stopId = ($data['transport_stop_id'] ?? null) ?: null;
            if ($routeId !== null) {
                $this->requireRecord(\App\Models\TransportRoute::class, (int) $routeId, $college->id, 'transport_route_id');
            }
            if ($stopId !== null) {
                $stop = \App\Models\TransportStop::withoutGlobalScopes()
                    ->where('college_id', $college->id)
                    ->whereNull('deleted_at')
                    ->find((int) $stopId);
                if (! $stop) {
                    throw ValidationException::withMessages(['transport_stop_id' => 'Select a stop belonging to the active college.']);
                }
                // A narrowed stop must sit on the narrowed route.
                if ($routeId !== null && (int) $stop->route_id !== (int) $routeId) {
                    throw ValidationException::withMessages(['transport_stop_id' => 'The selected stop must belong to the selected route.']);
                }
            }

            $this->assertPeriod($data['effective_from'], $data['effective_until'] ?? null);

            $old = $structure->only(self::AUDITED_STRUCTURE);
            $structure->fill([
                'academic_year_id' => $year->id,
                'transport_route_id' => $routeId,
                'transport_stop_id' => $stopId,
                'name' => $data['name'],
                'code' => strtoupper(trim((string) $data['code'])),
                'amount' => $this->assertAmount($data['amount']),
                'effective_from' => $data['effective_from'],
                'effective_until' => ($data['effective_until'] ?? null) ?: null,
                'status' => $data['status'],
                'remarks' => ($data['remarks'] ?? null) ?: null,
            ]);
            $structure->college_id = $college->id;
            $structure->updated_by = $actor->getKey();
            if ($creating) {
                $structure->created_by = $actor->getKey();
            }

            // Identifiers remain reserved on archived records (including history).
            $duplicate = TransportFeeStructure::withoutGlobalScopes()
                ->withTrashed()
                ->where('college_id', $college->id)
                ->where('code', $structure->code)
                ->when(! $creating, fn ($q) => $q->whereKeyNot($structure->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['code' => 'This code is already used, including archived records.']);
            }

            $structure->save();
            $this->audit->record('transport_fee_structures'.($creating ? '.created' : '.updated'), $structure, $creating ? [] : $old, $structure->only(self::AUDITED_STRUCTURE));

            return $structure->refresh();
        });
    }

    public function deleteStructure(TransportFeeStructure $structure, User $actor): void
    {
        DB::transaction(function () use ($structure, $actor): void {
            $college = $this->lockCollege();
            $this->assertTenant($structure, $college->id);
            $structure = TransportFeeStructure::withoutGlobalScopes()->lockForUpdate()->findOrFail($structure->id);

            $inUse = StudentTransportFeeAssignment::withoutGlobalScopes()
                ->where('transport_fee_structure_id', $structure->id)
                ->where('college_id', $college->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($inUse) {
                throw ValidationException::withMessages([
                    'code' => 'This fee structure is in use by transport fee assignments. Mark it inactive instead.',
                ]);
            }

            $old = $structure->only(self::AUDITED_STRUCTURE);
            $structure->updated_by = $actor->getKey();
            $structure->save();
            $structure->delete();
            $this->audit->record('transport_fee_structures.deleted', $structure, $old, []);
        });
    }

    // ------------------------------------------------ transport fee assignments

    /**
     * @param  array{student_transport_assignment_id: int, transport_fee_structure_id: int, effective_from: string, effective_until?: string|null, status: string, remarks?: string|null}  $data
     */
    public function assignFee(College $college, array $data, User $actor): StudentTransportFeeAssignment
    {
        return DB::transaction(function () use ($college, $data, $actor): StudentTransportFeeAssignment {
            $transport = $this->resolveTransportAssignment($college, (int) $data['student_transport_assignment_id']);
            $structure = $this->resolveStructure($college, (int) $data['transport_fee_structure_id']);

            $this->assertContextMatches($transport, $structure);
            $this->assertPeriod($data['effective_from'], $data['effective_until'] ?? null);

            // Serialize fee movements for this transport assignment so two
            // concurrent requests cannot both pass the overlap check below.
            StudentTransportAssignment::withoutGlobalScopes()
                ->whereKey($transport->getKey())
                ->lockForUpdate()
                ->first();

            if (($data['status'] ?? 'active') === StudentTransportFeeAssignment::STATUS_ACTIVE) {
                $this->assertNoActiveOverlap((int) $transport->id, $data['effective_from'], $data['effective_until'] ?? null);
            }

            // The amount is COMPUTED SERVER-SIDE from the fee structure and
            // frozen on the assignment — a browser-supplied amount is never read.
            $amount = FeeLedger::money($structure->amount);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'transport_fee_structure_id' => 'The selected transport fee structure has no positive amount and cannot be assigned.',
                ]);
            }

            $assignment = new StudentTransportFeeAssignment([
                'college_id' => $college->getKey(),
                'student_transport_assignment_id' => $transport->getKey(),
                'transport_fee_structure_id' => $structure->getKey(),
                // Stamped server-side from the transport assignment.
                'academic_year_id' => $transport->academic_year_id,
                'amount' => $amount,
                'effective_from' => $data['effective_from'],
                'effective_until' => ($data['effective_until'] ?? null) ?: null,
                'status' => $data['status'] ?? StudentTransportFeeAssignment::STATUS_ACTIVE,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            try {
                $assignment->save();
            } catch (\Illuminate\Database\QueryException) {
                // Partial unique index (SQLite/Postgres) rejected a racing insert.
                throw ValidationException::withMessages(['effective_from' => self::OVERLAP_MESSAGE]);
            }

            $this->audit->record('student_transport_fee_assignments.created', $assignment, [], $assignment->only(self::AUDITED_ASSIGNMENT));

            return $assignment->refresh();
        });
    }

    /**
     * Bookkeeping update of a fee assignment. The transport assignment, the fee
     * structure and the snapshotted amount are IMMUTABLE — re-pricing would
     * silently rewrite financial history. Cancel this assignment and create a
     * new one instead.
     *
     * @param  array{effective_from?: string, effective_until?: string|null, status?: string, remarks?: string|null}  $data
     */
    public function updateFeeAssignment(StudentTransportFeeAssignment $assignment, array $data, User $actor): StudentTransportFeeAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor): StudentTransportFeeAssignment {
            /** @var StudentTransportFeeAssignment $locked */
            $locked = StudentTransportFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTenant($locked, app(TenantContext::class)->id());

            $old = $locked->only(self::AUDITED_ASSIGNMENT);

            $from = $data['effective_from'] ?? ($locked->effective_from?->format('Y-m-d') ?: (string) $locked->effective_from);
            $until = array_key_exists('effective_until', $data) ? ($data['effective_until'] ?: null) : ($locked->effective_until?->format('Y-m-d'));
            $this->assertPeriod((string) $from, $until);

            $status = $data['status'] ?? $locked->status;

            if ($status === StudentTransportFeeAssignment::STATUS_ACTIVE) {
                StudentTransportAssignment::withoutGlobalScopes()
                    ->whereKey($locked->student_transport_assignment_id)
                    ->lockForUpdate()
                    ->first();

                $this->assertNoActiveOverlap(
                    (int) $locked->student_transport_assignment_id,
                    $from,
                    $until,
                    $locked->getKey(),
                );
            }

            $locked->effective_from = $from;
            $locked->effective_until = $until ?: null;
            $locked->status = $status;
            if (array_key_exists('remarks', $data)) {
                $locked->remarks = ($data['remarks'] ?? null) ?: null;
            }
            $locked->updated_by = $actor->getKey();
            $locked->save();

            $this->audit->record('student_transport_fee_assignments.updated', $locked, $old, $locked->only(self::AUDITED_ASSIGNMENT));

            return $locked->refresh();
        });
    }

    /** Soft delete (history preserved); money movements block deletion. */
    public function deleteFeeAssignment(StudentTransportFeeAssignment $assignment, User $actor): void
    {
        DB::transaction(function () use ($assignment, $actor): void {
            /** @var StudentTransportFeeAssignment $locked */
            $locked = StudentTransportFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTenant($locked, app(TenantContext::class)->id());

            $payments = FeePayment::withoutGlobalScopes()
                ->where('transport_fee_assignment_id', $locked->getKey())
                ->where('college_id', $locked->college_id)
                ->whereNull('deleted_at')
                ->exists();
            if ($payments) {
                throw ValidationException::withMessages([
                    'status' => 'This transport fee assignment has Finance collections recorded against it and cannot be deleted. Cancel it instead.',
                ]);
            }

            $old = $locked->only(self::AUDITED_ASSIGNMENT);
            $locked->updated_by = $actor->getKey();
            $locked->save();
            $locked->delete();
            $this->audit->record('student_transport_fee_assignments.deleted', $locked, $old, []);
        });
    }

    // ----------------------------------------------------------- ledger reads

    /**
     * The ledger summary of one fee assignment, derived live from the existing
     * Finance transaction rows (fee_payments / fee_refunds) with the shared
     * FeeLedger formula. Nothing here writes.
     *
     * @return array{assigned: float, concession: float, paid: float, refunded: float, net_collected: float, outstanding: float, status: string}
     */
    public function summaryFor(StudentTransportFeeAssignment $assignment): array
    {
        return FeeLedger::summary(
            (float) $assignment->amount,
            0.0,
            $this->paidSum($assignment),
            $this->refundedSum($assignment),
        );
    }

    /**
     * Ledger summaries for a page of assignments, one query per total set
     * (pre-aggregated), keyed by assignment id.
     *
     * @param  Collection<int, StudentTransportFeeAssignment>  $assignments
     * @return array<int, array{assigned: float, concession: float, paid: float, refunded: float, net_collected: float, outstanding: float, status: string}>
     */
    public function ledgerFor(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        $ids = $assignments->pluck('id')->all();
        $collegeId = app(TenantContext::class)->id();

        $paid = $this->totalsSub('fee_payments', 'transport_fee_assignment_id', $ids, $collegeId)->get();
        $refunded = $this->refundTotalsSub($ids, $collegeId)->get();

        $ledger = [];
        foreach ($assignments as $assignment) {
            $ledger[$assignment->getKey()] = FeeLedger::summary(
                (float) $assignment->amount,
                0.0,
                (float) ($paid->firstWhere('assignment_id', $assignment->getKey())->total ?? 0),
                (float) ($refunded->firstWhere('assignment_id', $assignment->getKey())->total ?? 0),
            );
        }

        return $ledger;
    }

    // ------------------------------------------------------------- helpers

    private function resolveTransportAssignment(College $college, int $id): StudentTransportAssignment
    {
        $transport = StudentTransportAssignment::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($id);

        if (! $transport) {
            throw ValidationException::withMessages(['student_transport_assignment_id' => 'Select a student transport assignment belonging to the active college.']);
        }

        if ($transport->status !== StudentTransportAssignment::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['student_transport_assignment_id' => 'Only an active transport assignment can be charged.']);
        }

        return $transport;
    }

    private function resolveStructure(College $college, int $id): TransportFeeStructure
    {
        $structure = TransportFeeStructure::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($id);

        if (! $structure) {
            throw ValidationException::withMessages(['transport_fee_structure_id' => 'Select a transport fee structure belonging to the active college.']);
        }

        return $structure;
    }

    /** The structure must fit the transport assignment's year and narrowing. */
    private function assertContextMatches(StudentTransportAssignment $transport, TransportFeeStructure $structure): void
    {
        if ((int) $structure->academic_year_id !== (int) $transport->academic_year_id) {
            throw ValidationException::withMessages(['transport_fee_structure_id' => 'The selected transport fee structure belongs to a different academic year.']);
        }

        if ($structure->transport_route_id !== null && (int) $structure->transport_route_id !== (int) $transport->transport_route_id) {
            throw ValidationException::withMessages(['transport_fee_structure_id' => 'The selected transport fee structure is for a different route.']);
        }

        if ($structure->transport_stop_id !== null && (int) $structure->transport_stop_id !== (int) $transport->transport_stop_id) {
            throw ValidationException::withMessages(['transport_fee_structure_id' => 'The selected transport fee structure is for a different stop.']);
        }
    }

    /**
     * No two ACTIVE fee assignments of one transport assignment may have
     * overlapping applicability periods. An open-ended period (null effective_until)
     * overlaps everything after its start.
     */
    private function assertNoActiveOverlap(int $transportAssignmentId, string $from, ?string $until, ?int $ignoreId = null): void
    {
        $existing = StudentTransportFeeAssignment::withoutGlobalScopes()
            ->where('student_transport_assignment_id', $transportAssignmentId)
            ->where('status', StudentTransportFeeAssignment::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['effective_from', 'effective_until']);

        foreach ($existing as $row) {
            $rowFrom = $row->effective_from?->format('Y-m-d') ?: (string) $row->effective_from;
            $rowUntil = $row->effective_until?->format('Y-m-d');
            $overlaps = ($until === null || $until === '' ? true : $until >= $rowFrom)
                && ($rowUntil === null || $rowUntil === '' ? true : $rowUntil >= $from);

            if ($overlaps) {
                throw ValidationException::withMessages(['effective_from' => self::OVERLAP_MESSAGE]);
            }
        }
    }

    private function assertPeriod(string $from, ?string $until): void
    {
        if ($until !== null && $until !== '' && $until < $from) {
            throw ValidationException::withMessages(['effective_until' => 'The effective-until date must not be before the effective-from date.']);
        }
    }

    /** Decimal-safe amount validation (never read a balance from the browser). */
    private function assertAmount(mixed $amount): float
    {
        $value = FeeLedger::money($amount);

        if ($value <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        if ($value > TransportFeeStructure::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => 'The amount exceeds the maximum supported value.']);
        }

        return $value;
    }

    private function paidSum(StudentTransportFeeAssignment $assignment): float
    {
        return (float) FeePayment::withoutGlobalScopes()
            ->where('transport_fee_assignment_id', $assignment->getKey())
            ->where('college_id', $assignment->college_id)
            ->whereNull('deleted_at')
            ->where('status', FeePayment::STATUS_COMPLETED)
            ->sum('amount');
    }

    private function refundedSum(StudentTransportFeeAssignment $assignment): float
    {
        return (float) DB::table('fee_refunds')
            ->join('fee_payments', 'fee_payments.id', '=', 'fee_refunds.fee_payment_id')
            ->where('fee_payments.transport_fee_assignment_id', $assignment->getKey())
            ->where('fee_payments.college_id', $assignment->college_id)
            ->where('fee_refunds.college_id', $assignment->college_id)
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)
            ->sum('fee_refunds.amount');
    }

    private function totalsSub(string $table, string $column, array $ids, ?int $collegeId): QueryBuilder
    {
        return DB::table($table)
            ->whereIn($column, $ids)
            ->when($collegeId !== null, fn (QueryBuilder $q) => $q->where('college_id', $collegeId))
            ->whereNull('deleted_at')
            ->where('status', FeePayment::STATUS_COMPLETED)
            ->groupBy($column)
            ->selectRaw("{$column} as assignment_id, SUM(amount) as total");
    }

    private function refundTotalsSub(array $ids, ?int $collegeId): QueryBuilder
    {
        return DB::table('fee_refunds')
            ->join('fee_payments', 'fee_payments.id', '=', 'fee_refunds.fee_payment_id')
            ->whereIn('fee_payments.transport_fee_assignment_id', $ids)
            ->when($collegeId !== null, fn (QueryBuilder $q) => $q
                ->where('fee_refunds.college_id', $collegeId)
                ->where('fee_payments.college_id', $collegeId))
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)
            ->groupBy('fee_payments.transport_fee_assignment_id')
            ->selectRaw('fee_payments.transport_fee_assignment_id as assignment_id, SUM(fee_refunds.amount) as total');
    }

    private function requireRecord(string $class, int $id, int $collegeId, string $field): Model
    {
        $record = $class::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($id);

        if (! $record) {
            throw ValidationException::withMessages([$field => 'Select a record belonging to the active college.']);
        }

        return $record;
    }

    private function lockCollege(): College
    {
        return College::query()->lockForUpdate()->findOrFail(app(TenantContext::class)->require()->id);
    }

    private function assertTenant(Model $record, ?int $college): void
    {
        abort_unless($college !== null && (int) $record->college_id === $college, 403);
    }
}
