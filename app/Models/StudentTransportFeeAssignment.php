<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentTransportFeeAssignment — one TransportFeeStructure charged to one
 * existing STUDENT TRANSPORT ASSIGNMENT.
 *
 * The student, enrollment, academic year, route and stop are NOT copied here:
 * they stay owned by the transport assignment. `academic_year_id` is stamped
 * server-side from that assignment for report filtering.
 *
 * `amount` is a deliberate FINANCIAL FACT: the fee structure's amount
 * snapshotted server-side at assignment time (never read from the browser), so
 * later edits to the structure never silently re-price an existing student.
 *
 * MONEY IS NOT STORED HERE. Collections are the EXISTING Finance fee_payments
 * rows pointed at this assignment (`fee_payments.transport_fee_assignment_id`),
 * receipts stay printable projections of those payments, and every balance is
 * derived live with the shared FeeLedger arithmetic — there is no parallel
 * payment, receipt or ledger system.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class StudentTransportFeeAssignment extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that still carry a payable balance. */
    public const PAYABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
    ];

    /** The largest amount the decimal(12,2) column can hold. */
    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
        'student_transport_assignment_id',
        'transport_fee_structure_id',
        'academic_year_id',
        'amount',
        'effective_from',
        'effective_until',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function studentTransportAssignment(): BelongsTo
    {
        return $this->belongsTo(StudentTransportAssignment::class);
    }

    public function transportFeeStructure(): BelongsTo
    {
        return $this->belongsTo(TransportFeeStructure::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** All Finance collections recorded against this assignment. */
    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class, 'transport_fee_assignment_id');
    }

    /** Collections that count towards the paid amount (cancellations excluded). */
    public function validPayments(): HasMany
    {
        return $this->hasMany(FeePayment::class, 'transport_fee_assignment_id')
            ->where('fee_payments.status', FeePayment::STATUS_COMPLETED);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** A cancelled assignment carries no payable balance. */
    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES, true);
    }
}
