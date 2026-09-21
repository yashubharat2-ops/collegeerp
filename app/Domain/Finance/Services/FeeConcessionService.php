<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Support\FeeLedger;
use App\Models\FeeConcession;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FeeConcessionService — discounts / concessions on a student fee assignment.
 *
 * Server-side rules, always re-checked under an assignment row lock so two
 * concurrent requests cannot exceed the applicable fee:
 *
 *   percentage value ∈ [0, 100]        (a fixed concession is non-negative money)
 *   amount = value                for fixed
 *   amount = round(assigned × value ÷ 100, 2)        for percentage
 *   Σ applicable concessions ≤ assigned amount (the snapshot on the assignment)
 *
 * `amount` is ALWAYS computed here from the assignment snapshot: the browser can
 * never submit a calculated amount, and a submitted one is ignored.
 *
 * Approval is server-controlled. Only the approve action writes approved_by /
 * approved_at, and editing the amount-bearing fields (type / value) resets the
 * concession to `pending` and clears the previous approval — an approval is for
 * a specific amount, not for whatever the row later becomes.
 *
 * Historical records are preserved: deleting a concession soft-deletes the row
 * (and audits it) rather than removing the history.
 */
class FeeConcessionService
{
    private const AUDITED = [
        'student_fee_assignment_id',
        'type',
        'value',
        'amount',
        'reason',
        'status',
        'approved_by',
        'approved_at',
    ];

    /** Statuses a user may set directly; `approved` is reserved for approve(). */
    private const SETTABLE_STATUSES = [
        FeeConcession::STATUS_PENDING,
        FeeConcession::STATUS_REJECTED,
        FeeConcession::STATUS_CANCELLED,
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{type: string, value: mixed, reason?: string|null, status?: string}  $data
     */
    public function create(StudentFeeAssignment $assignment, array $data, User $actor): FeeConcession
    {
        $this->assertTenant($assignment);

        return DB::transaction(function () use ($assignment, $data, $actor): FeeConcession {
            $locked = $this->lockAssignment($assignment);

            $type = (string) $data['type'];
            $value = FeeLedger::money($data['value']);
            $amount = $this->computeAmount($type, $value, $locked);

            $this->assertWithinApplicableFee($locked, $amount);

            $concession = FeeConcession::create([
                'college_id' => $locked->college_id,
                'student_fee_assignment_id' => $locked->getKey(),
                'type' => $type,
                'value' => $value,
                'amount' => $amount,
                'reason' => ($data['reason'] ?? null) ?: null,
                'status' => $data['status'] ?? FeeConcession::STATUS_PENDING,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record('fee_concessions.created', $concession, [], $concession->only(self::AUDITED));

            return $concession->refresh();
        });
    }

    /**
     * @param  array{type?: string, value?: mixed, reason?: string|null, status?: string}  $data
     */
    public function update(FeeConcession $concession, array $data, User $actor): FeeConcession
    {
        $this->assertTenant($concession);

        return DB::transaction(function () use ($concession, $data, $actor): FeeConcession {
            $old = $concession->only(self::AUDITED);
            $assignment = $this->lockAssignment($concession->assignment()->firstOrFail());

            $amountBearingChange = array_key_exists('type', $data) || array_key_exists('value', $data);

            if (array_key_exists('type', $data)) {
                $concession->type = (string) $data['type'];
            }

            if (array_key_exists('value', $data)) {
                $concession->value = FeeLedger::money($data['value']);
            }

            if ($amountBearingChange) {
                $concession->amount = $this->computeAmount(
                    (string) $concession->type,
                    (float) $concession->value,
                    $assignment,
                );

                // An approval applies to one specific amount: any change to the
                // amount-bearing fields sends the concession back for approval.
                $concession->status = FeeConcession::STATUS_PENDING;
                $concession->approved_by = null;
                $concession->approved_at = null;
            }

            if (array_key_exists('reason', $data)) {
                $concession->reason = ($data['reason'] ?? null) ?: null;
            }

            if (array_key_exists('status', $data) && in_array($data['status'], self::SETTABLE_STATUSES, true)) {
                $concession->status = $data['status'];
            }

            // The concession being edited never counts twice towards the cap.
            $this->assertWithinApplicableFee($assignment, (float) $concession->amount, $concession->getKey());

            $concession->updated_by = $actor->getKey();
            $concession->save();

            $this->audit->record('fee_concessions.updated', $concession, $old, $concession->only(self::AUDITED));

            return $concession->refresh();
        });
    }

    /**
     * Approve a concession. The approval metadata is written only here.
     */
    public function approve(FeeConcession $concession, User $actor): FeeConcession
    {
        $this->assertTenant($concession);

        return DB::transaction(function () use ($concession, $actor): FeeConcession {
            if (in_array($concession->status, FeeConcession::INVALID_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'A rejected or cancelled concession cannot be approved.',
                ]);
            }

            $old = $concession->only(self::AUDITED);
            $assignment = $this->lockAssignment($concession->assignment()->firstOrFail());

            // The assignment may have changed since the concession was raised.
            $this->assertWithinApplicableFee($assignment, (float) $concession->amount, $concession->getKey());

            $concession->status = FeeConcession::STATUS_APPROVED;
            $concession->approved_by = $actor->getKey();
            $concession->approved_at = now();
            $concession->updated_by = $actor->getKey();
            $concession->save();

            $this->audit->record('fee_concessions.approved', $concession, $old, $concession->only(self::AUDITED));

            return $concession->refresh();
        });
    }

    /**
     * Soft delete: the concession stops reducing the balance, the row and its
     * audit trail remain in the database.
     */
    public function delete(FeeConcession $concession, User $actor): void
    {
        $this->assertTenant($concession);

        DB::transaction(function () use ($concession): void {
            $snapshot = $concession->only(self::AUDITED);
            $concession->delete();

            $this->audit->record('fee_concessions.deleted', $concession, $snapshot, []);
        });
    }

    // ------------------------------------------------------------- helpers

    private function lockAssignment(StudentFeeAssignment $assignment): StudentFeeAssignment
    {
        /** @var StudentFeeAssignment $locked */
        $locked = StudentFeeAssignment::withoutGlobalScopes()
            ->whereKey($assignment->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->isPayable()) {
            throw ValidationException::withMessages([
                'student_fee_assignment_id' => 'A cancelled fee assignment cannot receive a concession.',
            ]);
        }

        return $locked;
    }

    /**
     * The concession amount, always derived server-side from the assignment
     * snapshot. Nothing the browser submits is trusted.
     */
    private function computeAmount(string $type, float $value, StudentFeeAssignment $assignment): float
    {
        if (! in_array($type, FeeConcession::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'The concession type is invalid.']);
        }

        if ($type === FeeConcession::TYPE_PERCENTAGE && ($value < 0 || $value > 100)) {
            throw ValidationException::withMessages(['value' => 'A percentage concession must be between 0 and 100.']);
        }

        if ($type === FeeConcession::TYPE_FIXED && $value < 0) {
            throw ValidationException::withMessages(['value' => 'A fixed concession must not be negative.']);
        }

        return FeeLedger::concessionAmount($type, $value, (float) $assignment->assigned_amount);
    }

    /**
     * A concession — together with the assignment's other applicable
     * concessions — can never exceed the applicable fee.
     */
    private function assertWithinApplicableFee(
        StudentFeeAssignment $assignment,
        float $amount,
        ?int $ignoreConcessionId = null,
    ): void {
        $assigned = FeeLedger::money($assignment->assigned_amount);

        $other = (float) FeeConcession::withoutGlobalScopes()
            ->where('student_fee_assignment_id', $assignment->getKey())
            ->where('college_id', $assignment->college_id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', FeeConcession::INVALID_STATUSES)
            ->when($ignoreConcessionId !== null, fn ($query) => $query->whereKeyNot($ignoreConcessionId))
            ->sum('amount');

        $total = FeeLedger::money($other + $amount);

        if ($total > $assigned + FeeLedger::TOLERANCE) {
            throw ValidationException::withMessages([
                'value' => 'The concession would exceed the applicable fee: the remaining concessionable amount is '
                    .number_format(max(0, FeeLedger::money($assigned - $other)), 2).'.',
            ]);
        }
    }

    /**
     * Guards both the assignment (create path) and the concession itself.
     */
    private function assertTenant(FeeConcession|StudentFeeAssignment $record): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $record->college_id === (int) $collegeId, 403);
    }
}
