<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * InventoryMaintenance — one maintenance event for an individual asset
 * (Inventory / Asset Management, Phase 3).
 *
 * Always a reference to an EXISTING asset (`inventory_items` row with
 * `item_type = 'asset'`) — there is no separate maintenance-owned asset and
 * the composite `(item_id, college_id)` foreign key keeps it on the same
 * tenant. Optional `vendor_id` reuses the Phase 1 vendor master for
 * externally performed work.
 *
 * Unlike the append-only assignment history, a maintenance record is a live
 * work order: its status walks scheduled → in progress → completed as the
 * work happens (costs and completion date filled in along the way), so the
 * table supports editing and soft deletes. There is still no delete route in
 * Phase 3 — records are corrected, not thrown away.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope.
 */
class InventoryMaintenance extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const TYPE_PREVENTIVE = 'preventive';

    public const TYPE_REPAIR = 'repair';

    public const TYPE_INSPECTION = 'inspection';

    public const TYPE_CALIBRATION = 'calibration';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_PREVENTIVE,
        self::TYPE_REPAIR,
        self::TYPE_INSPECTION,
        self::TYPE_CALIBRATION,
        self::TYPE_OTHER,
    ];

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'college_id',
        'item_id',
        'vendor_id',
        'title',
        'maintenance_type',
        'status',
        'scheduled_on',
        'completed_on',
        'cost',
        'performed_by',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'completed_on' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(InventoryVendor::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<InventoryMaintenance>  $query
     * @return Builder<InventoryMaintenance>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
