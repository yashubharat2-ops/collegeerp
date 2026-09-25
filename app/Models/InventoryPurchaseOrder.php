<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * InventoryPurchaseOrder — a college's order to a vendor (Inventory / Asset
 * Management, Phase 2).
 *
 * The header carries the vendor, the order number and the lifecycle status;
 * the quantities live on the lines (`InventoryPurchaseOrderItem`). Nothing is
 * paid here — Finance owns payments and this model creates no payment rows.
 *
 * Lifecycle: `draft` → `submitted` → `partially_received` → `received`, with
 * `cancelled` reachable from anything not yet fully received. A draft is the
 * only editable state, because a submitted order is a promise to the vendor
 * and a received one is already reflected in the stock ledger.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class InventoryPurchaseOrder extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_PARTIALLY_RECEIVED,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    /** Only a draft may be edited or deleted. */
    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
    ];

    /** Only an order that is out with the vendor can be received against. */
    public const RECEIVABLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_PARTIALLY_RECEIVED,
    ];

    /** A draft may be sent to the vendor. */
    public const SUBMITTABLE_STATUSES = [
        self::STATUS_DRAFT,
    ];

    /** Anything not fully received may still be called off. */
    public const CANCELLABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_PARTIALLY_RECEIVED,
    ];

    protected $fillable = [
        'college_id',
        'vendor_id',
        'number',
        'po_date',
        'expected_date',
        'status',
        'total_amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'expected_date' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    /** Order numbers are stored upper-cased and trimmed so "po-01" and "PO-01" are the same order. */
    public function setNumberAttribute($value): void
    {
        $this->attributes['number'] = strtoupper(trim((string) $value));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, self::RECEIVABLE_STATUSES, true);
    }

    public function isSubmittable(): bool
    {
        return in_array($this->status, self::SUBMITTABLE_STATUSES, true);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(InventoryVendor::class, 'vendor_id');
    }

    /**
     * @return HasMany<InventoryPurchaseOrderItem>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryPurchaseOrderItem::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<InventoryStockMovement>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryStockMovement::class, 'purchase_order_id');
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
