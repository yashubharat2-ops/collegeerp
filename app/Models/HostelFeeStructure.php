<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelFeeStructure — the hostel-side pricing master.
 *
 * Answers "how much does hostel accommodation cost for this academic year
 * and period?" for one college. Amounts are entered per college and
 * snapshotted onto each student's fee assignment at assignment time.
 *
 * THIS IS NOT A FINANCE TABLE: it holds no payments, receipts or balances.
 * Collections happen through the existing Finance fee_payments rows.
 */
class HostelFeeStructure extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    public const FREQUENCY_ONE_TIME = 'one-time';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_YEARLY = 'yearly';
    public const FREQUENCY_SEMESTER = 'semester';
    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCIES = [
        self::FREQUENCY_ONE_TIME,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_YEARLY,
        self::FREQUENCY_SEMESTER,
        self::FREQUENCY_QUARTERLY,
    ];

    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
        'academic_year_id',
        'name',
        'code',
        'amount',
        'frequency',
        'effective_from',
        'effective_until',
        'status',
        'description',
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

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
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
}
