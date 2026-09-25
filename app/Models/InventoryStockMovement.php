<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InventoryStockMovement — one immutable row of the stock ledger
 * (Inventory / Asset Management, Phase 2).
 *
 * Every change of on-hand quantity passes through this table: goods received
 * against a purchase order, manual stock in and out, and corrections. A row
 * is written once and never edited or deleted — a correction is a new
 * movement, which is what keeps `inventory_items.quantity` reconcilable
 * against the ledger.
 *
 * `quantity` is a positive magnitude; `direction` carries the sign, so the
 * ledger can be filtered (and indexed) without decoding the type.
 * `balance_after` snapshots the on-hand quantity at write time.
 */
class InventoryStockMovement extends Model
{
    use BelongsToCollege;

    /** Opening balance captured when an item is first recorded. */
    public const TYPE_OPENING = 'opening';

    /** Goods received against a purchase order. */
    public const TYPE_PURCHASE_RECEIPT = 'purchase_receipt';

    /** Manual receipt with no purchase order behind it. */
    public const TYPE_STOCK_IN = 'stock_in';

    /** Manual issue or consumption. */
    public const TYPE_STOCK_OUT = 'stock_out';

    /** Correction of a previously recorded balance. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_OPENING,
        self::TYPE_PURCHASE_RECEIPT,
        self::TYPE_STOCK_IN,
        self::TYPE_STOCK_OUT,
        self::TYPE_ADJUSTMENT,
    ];

    /** The types a user may record by hand on the Stock screen. */
    public const MANUAL_TYPES = [
        self::TYPE_STOCK_IN,
        self::TYPE_STOCK_OUT,
        self::TYPE_ADJUSTMENT,
    ];

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const DIRECTIONS = [
        self::DIRECTION_IN,
        self::DIRECTION_OUT,
    ];

    /** Types whose direction is fixed; an adjustment may go either way. */
    public const DIRECTION_BY_TYPE = [
        self::TYPE_OPENING => self::DIRECTION_IN,
        self::TYPE_PURCHASE_RECEIPT => self::DIRECTION_IN,
        self::TYPE_STOCK_IN => self::DIRECTION_IN,
        self::TYPE_STOCK_OUT => self::DIRECTION_OUT,
    ];

    protected $fillable = [
        'college_id',
        'item_id',
        'purchase_order_id',
        'type',
        'direction',
        'quantity',
        'balance_after',
        'unit_price',
        'reference',
        'reason',
        'notes',
        'movement_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    /** References (GRN numbers, challans) are stored upper-cased; empty means "not recorded". */
    public function setReferenceAttribute($value): void
    {
        $normalized = strtoupper(trim((string) $value));
        $this->attributes['reference'] = $normalized === '' ? null : $normalized;
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    /** The signed effect of this movement on the on-hand quantity. */
    public function signedQuantity(): string
    {
        return $this->isIncoming() ? $this->quantity : bcmul($this->quantity, '-1', 2);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchaseOrder::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<InventoryStockMovement>  $query
     * @return Builder<InventoryStockMovement>
     */
    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_IN);
    }

    /**
     * @param  Builder<InventoryStockMovement>  $query
     * @return Builder<InventoryStockMovement>
     */
    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUT);
    }
}
