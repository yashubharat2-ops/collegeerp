<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * InventoryCategory — a college-owned classification of items and assets
 * (Inventory / Asset Management, Phase 1).
 *
 * Categories are pure classification (Stationery, Furniture, Lab Equipment, …):
 * they hold no stock. Consumables and assets share this taxonomy; nothing
 * about a particular college's catalogue is hard-coded.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class InventoryCategory extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    /** Codes are stored upper-cased and trimmed so "stn" and "STN" are the same code. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Items and assets classified by this category (one master, both types). */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'category_id')->orderBy('name')->orderBy('id');
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
