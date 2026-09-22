<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TransportFeeStructure — the transport-side pricing master (transport fee
 * category / structure).
 *
 * It answers "how much does this route/stop combination cost for this academic
 * year and period?" for one college. Amounts are entered per college and
 * snapshotted onto each student's fee assignment at assignment time, so this
 * row is never a hardcoded price and editing it never re-prices history.
 *
 * Route/stop narrowing is OPTIONAL (a structure may price a whole route or a
 * single stop). Both references are composite foreign keys (id, college_id),
 * so a foreign college's route or stop cannot be referenced even by a crafted
 * request.
 *
 * THIS IS NOT A FINANCE TABLE: it holds no payments, receipts or balances.
 * Collections happen through the existing Finance fee_payments rows (see
 * StudentTransportFeeAssignment).
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class TransportFeeStructure extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /** The largest amount the decimal(12,2) column can hold. */
    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
        'academic_year_id',
        'transport_route_id',
        'transport_stop_id',
        'name',
        'code',
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

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function transportRoute(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class);
    }

    public function transportStop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class);
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
