<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\HostelAllocation;
use App\Models\HostelFeeAssignment;
use App\Models\HostelFeeStructure;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelFeeService — hostel fee structures (pricing master) and the
 * hostel fee assignments charged against existing hostel allocations.
 *
 * THIS SERVICE STORES NO PAYMENT MONEY. Collections are recorded through the
 * existing Finance module (FeePayment rows, one payment number series,
 * receipts as projections), and every balance here is DERIVED live from those
 * same rows with the shared FeeLedger arithmetic — there is no parallel
 * payment, receipt or ledger system:
 *
 *   outstanding = assigned amount − valid payments + valid refunds
 *
 * (hostel fees have no concessions; the concession term is zero).
 *
 * Guarantees, inside one transaction on locked rows:
 * - allocation and fee structure belong to ACTIVE college and are compatible
 * - allocation is valid (not cancelled)
 * - amount is SNAPSHOTTED SERVER-SIDE from fee structure and immutable
 * - two ACTIVE fee assignments for same allocation can never have OVERLAPPING periods
 * - tenant and actor fields server-controlled
 */
class HostelFeeService
{
    private const AUDITED_STRUCTURE = [
        'academic_year_id', 'name', 'code', 'amount', 'frequency',
        'effective_from', 'effective_until', 'status', 'description',
    ];

    private const AUDITED_ASSIGNMENT = [
        'hostel_allocation_id', 'hostel_fee_structure_id', 'academic_year_id',
        'assigned_amount', 'effective_from', 'effective_until', 'status', 'remarks',
    ];

    private const OVERLAP_MESSAGE = 'This hostel allocation already has an active hostel fee assignment for the selected period.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    // ------------------------------------------------ hostel fee structures

    /**
     * @param array{academic_year_id: int, name: string, code: string, amount: mixed, frequency?: string|null, effective_from?: string|null, effective_until?: string|null, status: string, description?: string|null} $data
     */
    public function saveStructure(HostelFeeStructure $structure, array $data, User $actor): HostelFeeStructure
    {
        return DB::transaction(function () use ($structure, $data, $actor): HostelFeeStructure {
            $college = $this->lockCollege();
            $creating = ! $structure->exists;
            if (! $creating) {
                $this->assertTenant($structure, $college->id);
                $structure = HostelFeeStructure::withoutGlobalScopes()->lockForUpdate()->findOrFail($structure->id);
            }

            $year = $this->requireRecord(AcademicYear::class, (int) $data['academic_year_id'], $college->id, 'academic_year_id');

            $this->assertPeriod($data['effective_from'] ?? null, $data['effective_until'] ?? null);

            $old = $structure->only(self::AUDITED_STRUCTURE);
            $structure->fill([
                'academic_year_id' => $year->id,
                'name' => $data['name'],
                'code' => strtoupper(trim((string) $data['code'])),
                'amount' => $this->assertAmount($data['amount']),
                'frequency' => $data['frequency'] ?? null,
                'effective_from' => ($data['effective_from'] ?? null) ?: null,
                'effective_until' => ($data['effective_until'] ?? null) ?: null,
                'status' => $data['status'],
                'description' => ($data['description'] ?? null) ?: null,
            ]);
            $structure->college_id = $college->id;
            $structure->updated_by = $actor->getKey();
            if ($creating) {
                $structure->created_by = $actor->getKey();
            }

            $duplicate = HostelFeeStructure::withoutGlobalScopes()
                ->withTrashed()
                ->where('college_id', $college->id)
                ->where('code', $structure->code)
                ->when(! $creating, fn ($q) => $q->whereKeyNot($structure->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['code' => 'This code is already used, including archived records.']);
            }

            $structure->save();
            $this->audit->record('hostel_fee_structures'.($creating ? '.created' : '.updated'), $structure, $creating ? [] : $old, $structure->only(self::AUDITED_STRUCTURE));

            return $structure->refresh();
        });
    }

    public function deleteStructure(HostelFeeStructure $structure, User $actor): void
    {
        DB::transaction(function () use ($structure, $actor): void {
            $college = $this->lockCollege();
            $this->assertTenant($structure, $college->id);
            $structure = HostelFeeStructure::withoutGlobalScopes()->lockForUpdate()->findOrFail($structure->id);

            $inUse = HostelFeeAssignment::withoutGlobalScopes()
                ->where('hostel_fee_structure_id', $structure->id)
                ->where('college_id', $college->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($inUse) {
                throw ValidationException::withMessages([
                    'code' => 'This fee structure is in use by hostel fee assignments. Mark it inactive instead.',
                ]);
            }

            $old = $structure->only(self::AUDITED_STRUCTURE);
            $structure->updated_by = $actor->getKey();
            $structure->save();
            $structure->delete();
            $this->audit->record('hostel_fee_structures.deleted', $structure, $old, []);
        });
    }

    // ------------------------------------------------ hostel fee assignments

    /**
     * @param array{hostel_allocation_id: int, hostel_fee_structure_id: int, effective_from: string, effective_until?: string|null, status: string, remarks?: string|null} $data
     */
    public function assignFee(College $college, array $data, User $actor): HostelFeeAssignment
    {
        return DB::transaction(function () use ($college, $data, $actor): HostelFeeAssignment {
            $allocation = $this->resolveAllocation($college, (int) $data['hostel_allocation_id']);
            $structure = $this->resolveStructure($college, (int) $data['hostel_fee_structure_id']);

            $this->assertContextMatches($allocation, $structure);
            $this->assertPeriod($data['effective_from'], $data['effective_until'] ?? null);

            // Serialize fee movements for this allocation
            HostelAllocation::withoutGlobalScopes()
                ->whereKey($allocation->getKey())
                ->lockForUpdate()
                ->first();

            if (($data['status'] ?? 'active') === HostelFeeAssignment::STATUS_ACTIVE) {
                $this->assertNoActiveOverlap((int) $allocation->id, $data['effective_from'], $data['effective_until'] ?? null);
            }

            $amount = FeeLedger::money($structure->amount);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'hostel_fee_structure_id' => 'The selected hostel fee structure has no positive amount and cannot be assigned.',
                ]);
            }

            $assignment = new HostelFeeAssignment([
                'college_id' => $college->getKey(),
                'hostel_allocation_id' => $allocation->getKey(),
                'hostel_fee_structure_id' => $structure->getKey(),
                'academic_year_id' => $allocation->academic_year_id,
                'assigned_amount' => $amount,
                'effective_from' => $data['effective_from'],
                'effective_until' => ($data['effective_until'] ?? null) ?: null,
                'status' => $data['status'] ?? HostelFeeAssignment::STATUS_ACTIVE,
                'remarks' => ($data['remarks'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $assignment->save();

            $this->audit->record('hostel_fee_assignments.created', $assignment, [], $assignment->only(self::AUDITED_ASSIGNMENT));

            return $assignment->refresh();
        });
    }

    /**
     * @param array{effective_from?: string, effective_until?: string|null, status?: string, remarks?: string|null} $data
     */
    public function updateFeeAssignment(HostelFeeAssignment $assignment, array $data, User $actor): HostelFeeAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor): HostelFeeAssignment {
            /** @var HostelFeeAssignment $locked */
            $locked = HostelFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTenant($locked, app(TenantContext::class)->id());

            $old = $locked->only(self::AUDITED_ASSIGNMENT);

            // Immutable: allocation and structure cannot be changed after creation
            if (array_key_exists('hostel_allocation_id', $data) && (int) $data['hostel_allocation_id'] !== (int) $locked->hostel_allocation_id) {
                throw ValidationException::withMessages(['hostel_allocation_id' => 'The hostel allocation cannot be changed. Create a new fee assignment instead.']);
            }
            if (array_key_exists('hostel_fee_structure_id', $data) && (int) $data['hostel_fee_structure_id'] !== (int) $locked->hostel_fee_structure_id) {
                throw ValidationException::withMessages(['hostel_fee_structure_id' => 'The fee structure cannot be changed. Create a new fee assignment instead.']);
            }

            if (array_key_exists('effective_from', $data)) {
                $locked->effective_from = $data['effective_from'];
            }
            if (array_key_exists('effective_until', $data)) {
                $locked->effective_until = ($data['effective_until'] ?? null) ?: null;
            }
            if (array_key_exists('status', $data)) {
                $locked->status = $data['status'];
            }
            if (array_key_exists('remarks', $data)) {
                $locked->remarks = ($data['remarks'] ?? null) ?: null;
            }

            $this->assertPeriod($locked->effective_from?->format('Y-m-d') ?: (string) $locked->effective_from, $locked->effective_until?->format('Y-m-d'));

            if ($locked->status === HostelFeeAssignment::STATUS_ACTIVE) {
                $this->assertNoActiveOverlap((int) $locked->hostel_allocation_id, $locked->effective_from->format('Y-m-d'), $locked->effective_until?->format('Y-m-d'), $locked->id);
            }

            $locked->updated_by = $actor->getKey();
            $locked->save();

            $this->audit->record('hostel_fee_assignments.updated', $locked, $old, $locked->only(self::AUDITED_ASSIGNMENT));

            return $locked->refresh();
        });
    }

    public function deleteFeeAssignment(HostelFeeAssignment $assignment, User $actor): void
    {
        DB::transaction(function () use ($assignment, $actor): void {
            /** @var HostelFeeAssignment $locked */
            $locked = HostelFeeAssignment::withoutGlobalScopes()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTenant($locked, app(TenantContext::class)->id());

            $payments = FeePayment::withoutGlobalScopes()
                ->where('hostel_fee_assignment_id', $locked->getKey())
                ->where('college_id', $locked->college_id)
                ->whereNull('deleted_at')
                ->exists();
            if ($payments) {
                throw ValidationException::withMessages([
                    'status' => 'This hostel fee assignment has Finance collections recorded against it and cannot be deleted. Cancel it instead.',
                ]);
            }

            $old = $locked->only(self::AUDITED_ASSIGNMENT);
            $locked->updated_by = $actor->getKey();
            $locked->save();
            $locked->delete();
            $this->audit->record('hostel_fee_assignments.deleted', $locked, $old, []);
        });
    }

    // ----------------------------------------------------------- ledger reads

    /**
     * @return array{assigned: float, concession: float, paid: float, refunded: float, net_collected: float, outstanding: float, status: string}
     */
    public function summaryFor(HostelFeeAssignment $assignment): array
    {
        return FeeLedger::summary(
            (float) $assignment->assigned_amount,
            0.0,
            $this->paidSum($assignment),
            $this->refundedSum($assignment),
        );
    }

    /**
     * @param Collection<int, HostelFeeAssignment> $assignments
     * @return array<int, array{assigned: float, concession: float, paid: float, refunded: float, net_collected: float, outstanding: float, status: string}>
     */
    public function ledgerFor(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        $ids = $assignments->pluck('id')->all();
        $collegeId = app(TenantContext::class)->id();

        $paid = $this->totalsSub('fee_payments', 'hostel_fee_assignment_id', $ids, $collegeId)->get();
        $refunded = $this->refundTotalsSub($ids, $collegeId)->get();

        $ledger = [];
        foreach ($assignments as $assignment) {
            $ledger[$assignment->getKey()] = FeeLedger::summary(
                (float) $assignment->assigned_amount,
                0.0,
                (float) ($paid->firstWhere('assignment_id', $assignment->getKey())->total ?? 0),
                (float) ($refunded->firstWhere('assignment_id', $assignment->getKey())->total ?? 0),
            );
        }

        return $ledger;
    }

    // ------------------------------------------------------------- helpers

    private function resolveAllocation(College $college, int $id): HostelAllocation
    {
        $allocation = HostelAllocation::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($id);

        if (! $allocation) {
            throw ValidationException::withMessages(['hostel_allocation_id' => 'Select a hostel allocation belonging to the active college.']);
        }

        if ($allocation->status === HostelAllocation::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['hostel_allocation_id' => 'A cancelled hostel allocation cannot be charged.']);
        }

        return $allocation;
    }

    private function resolveStructure(College $college, int $id): HostelFeeStructure
    {
        $structure = HostelFeeStructure::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->find($id);

        if (! $structure) {
            throw ValidationException::withMessages(['hostel_fee_structure_id' => 'Select a hostel fee structure belonging to the active college.']);
        }

        return $structure;
    }

    private function assertContextMatches(HostelAllocation $allocation, HostelFeeStructure $structure): void
    {
        if ((int) $structure->academic_year_id !== (int) $allocation->academic_year_id) {
            throw ValidationException::withMessages(['hostel_fee_structure_id' => 'The selected hostel fee structure belongs to a different academic year.']);
        }
    }

    private function assertNoActiveOverlap(int $allocationId, string $from, ?string $until, ?int $ignoreId = null): void
    {
        $existing = HostelFeeAssignment::withoutGlobalScopes()
            ->where('hostel_allocation_id', $allocationId)
            ->where('status', HostelFeeAssignment::STATUS_ACTIVE)
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

    private function assertPeriod(?string $from, ?string $until): void
    {
        if ($from !== null && $from !== '' && $until !== null && $until !== '' && $until < $from) {
            throw ValidationException::withMessages(['effective_until' => 'The effective-until date must not be before the effective-from date.']);
        }
    }

    private function assertAmount(mixed $amount): float
    {
        $value = FeeLedger::money($amount);

        if ($value <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        if ($value > HostelFeeStructure::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => 'The amount exceeds the maximum supported value.']);
        }

        return $value;
    }

    private function paidSum(HostelFeeAssignment $assignment): float
    {
        return (float) FeePayment::withoutGlobalScopes()
            ->where('hostel_fee_assignment_id', $assignment->getKey())
            ->where('college_id', $assignment->college_id)
            ->whereNull('deleted_at')
            ->where('status', FeePayment::STATUS_COMPLETED)
            ->sum('amount');
    }

    private function refundedSum(HostelFeeAssignment $assignment): float
    {
        return (float) DB::table('fee_refunds')
            ->join('fee_payments', 'fee_payments.id', '=', 'fee_refunds.fee_payment_id')
            ->where('fee_payments.hostel_fee_assignment_id', $assignment->getKey())
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
            ->whereIn('fee_payments.hostel_fee_assignment_id', $ids)
            ->when($collegeId !== null, fn (QueryBuilder $q) => $q
                ->where('fee_payments.college_id', $collegeId)
                ->where('fee_refunds.college_id', $collegeId))
            ->whereNull('fee_payments.deleted_at')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED)
            ->whereNotIn('fee_refunds.status', FeeRefund::INVALID_STATUSES)
            ->groupBy('fee_payments.hostel_fee_assignment_id')
            ->selectRaw('fee_payments.hostel_fee_assignment_id as assignment_id, SUM(fee_refunds.amount) as total');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param class-string<TModel> $class
     * @return TModel
     */
    private function requireRecord(string $class, int $id, int $collegeId, string $field): mixed
    {
        $record = $class::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->find($id);

        if (! $record) {
            throw ValidationException::withMessages([$field => 'Select a record belonging to the active college.']);
        }

        return $record;
    }

    private function assertTenant($record, int $collegeId): void
    {
        abort_unless((int) $record->college_id === $collegeId, 403);
    }

    private function lockCollege(): College
    {
        $collegeId = app(TenantContext::class)->id();
        abort_unless($collegeId !== null, 403);

        return College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    }
}
