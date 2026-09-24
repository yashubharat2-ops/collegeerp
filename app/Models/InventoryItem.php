<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * InventoryItem — the college-owned item / asset master (Inventory / Asset
 * Management, Phase 1).
 *
 * Consumables and fixed assets are the same master, distinguished only by
 * `item_type` (`consumable` or `asset`). There is no separate Asset model
 * and no separate assets table. Stock in/out, issue/return and assignment
 * are later phases; this row is the catalogue record only.
 *
 * Identifiers: `code` is required and unique among the college's active rows.
 * `serial_number` is optional and, when present, stored upper-cased and unique
 * among the college's active rows.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class InventoryItem extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    public const TYPE_CONSUMABLE = 'consumable';

    public const TYPE_ASSET = 'asset';

    public const TYPES = [
        self::TYPE_CONSUMABLE,
        self::TYPE_ASSET,
    ];

    protected $fillable = [
        'college_id',
        'category_id',
        'name',
        'code',
        'item_type',
        'brand',
        'model',
        'serial_number',
        'unit',
        'quantity',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    /** Codes are stored upper-cased and trimmed so "itm-01" and "ITM-01" are the same item. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    /** Serial numbers are stored upper-cased. An empty value is "no serial", not "". */
    public function setSerialNumberAttribute($value): void
    {
        $normalized = strtoupper(trim((string) $value));
        $this->attributes['serial_number'] = $normalized === '' ? null : $normalized;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isAsset(): bool
    {
        return $this->item_type === self::TYPE_ASSET;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
