<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelFeeAssignment — one HostelFeeStructure charged to one existing
 * HostelAllocation.
 *
 * The student, enrollment, academic year, hostel hierarchy are NOT copied —
 * they stay owned by the allocation. academic_year_id is stamped server-side
 * from that allocation for report filtering.
 *
 * assigned_amount is a deliberate FINANCIAL FACT: the fee structure's amount
 * snapshotted server-side at assignment time, so later edits to the structure
 * never silently re-price an existing student.
 *
 * MONEY IS NOT STORED HERE. Collections are the EXISTING Finance
 * fee_payments rows pointed at this assignment (fee_payments.hostel_fee_assignment_id),
 * receipts stay printable projections of those payments, and every balance is
 * derived live with the shared FeeLedger arithmetic.
 */
class HostelFeeAssignment extends Model
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

    public const PAYABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
    ];

    public const MAX_AMOUNT = 9999999999.99;

    protected $table = 'hostel_fee_assignments';

    protected $fillable = [
        'college_id',
        'hostel_allocation_id',
        'hostel_fee_structure_id',
        'academic_year_id',
        'assigned_amount',
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
            'assigned_amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function hostelAllocation(): BelongsTo
    {
        return $this->belongsTo(HostelAllocation::class, 'hostel_allocation_id');
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(HostelFeeStructure::class, 'hostel_fee_structure_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** All Finance collections recorded against this assignment. */
    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class, 'hostel_fee_assignment_id');
    }

    /** Collections that count towards the paid amount (cancellations excluded). */
    public function validPayments(): HasMany
    {
        return $this->hasMany(FeePayment::class, 'hostel_fee_assignment_id')
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

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE_STATUSES, true);
    }
}
