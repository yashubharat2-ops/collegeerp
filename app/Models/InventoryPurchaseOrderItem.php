<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InventoryPurchaseOrderItem — one ordered line of a purchase order
 * (Inventory / Asset Management, Phase 2).
 *
 * A line pairs an item of the college's catalogue with the ordered quantity
 * and the agreed unit price. `received_quantity` accumulates as consignments
 * arrive, so the outstanding quantity is always derivable and an order can be
 * received in parts.
 *
 * Lines are children of their header: they are replaced wholesale while the
 * order is a draft, and frozen once it is submitted. They are never
 * soft-deleted on their own.
 */
class InventoryPurchaseOrderItem extends Model
{
    use BelongsToCollege;

    protected $fillable = [
        'college_id',
        'purchase_order_id',
        'item_id',
        'quantity',
        'unit_price',
        'received_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'received_quantity' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    /** Line total, computed with bcmath so money never meets binary floats. */
    public function lineTotal(): string
    {
        return bcmul($this->quantity, $this->unit_price, 2);
    }

    /** Quantity still to arrive on this line. */
    public function remainingQuantity(): string
    {
        $remaining = bcsub($this->quantity, $this->received_quantity, 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }

    public function isFullyReceived(): bool
    {
        return bccomp($this->remainingQuantity(), '0', 2) === 0;
    }
}
