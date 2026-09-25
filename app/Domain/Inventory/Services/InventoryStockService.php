<?php

namespace App\Domain\Inventory\Services;

use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryStockService — the only way on-hand quantity changes.
 *
 * Phase 1 stored `inventory_items.quantity` as a plain editable number. From
 * Phase 2 the stock ledger is authoritative: every change is written as an
 * immutable `inventory_stock_movements` row and the item's on-hand quantity is
 * updated in the SAME transaction, so the ledger and the balance can never
 * drift apart. A correction is a new movement — movements are never edited or
 * deleted.
 *
 * Guarantees:
 *   - the item belongs to the ACTIVE college (re-checked here even when the
 *     caller came through a Form Request);
 *   - on-hand quantity can never go negative — an out movement larger than the
 *     balance is rejected with a field error, not applied half-way;
 *   - `balance_after` on the movement is the balance actually written;
 *   - every movement is audit-logged.
 */
class InventoryStockService
{
    private const AUDITED = [
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
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * Record a movement typed by hand on the Stock screen (stock in, stock out
     * or a correction).
     *
     * @param  array<string, mixed>  $data
     */
    public function record(InventoryItem $item, array $data, User $actor): InventoryStockMovement
    {
        $this->assertTenant($item);

        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, InventoryStockMovement::MANUAL_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Choose stock in, stock out or a correction.',
            ]);
        }

        return $this->apply($item, $type, $this->quantity($data['quantity'] ?? null), $this->resolveDirection($type, $data['direction'] ?? null), $actor, [
            'unit_price' => $this->optionalAmount($data['unit_price'] ?? null),
            'reference' => $this->optionalText($data['reference'] ?? null),
            'reason' => $this->optionalText($data['reason'] ?? null),
            'notes' => $this->optionalText($data['notes'] ?? null),
            'movement_date' => $data['movement_date'] ?? null,
        ]);
    }

    /**
     * Write one movement and move the item's on-hand quantity in the same
     * transaction. Called by the manual Stock screen and by the purchase order
     * receiving flow (which adds its own `purchase_order_id` and unit price).
     *
     * @param  array<string, mixed>  $meta
     */
    public function apply(
        InventoryItem $item,
        string $type,
        string $quantity,
        string $direction,
        User $actor,
        array $meta = [],
    ): InventoryStockMovement {
        $this->assertTenant($item);

        if (bccomp($quantity, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'The quantity must be greater than zero.',
            ]);
        }

        if (! in_array($direction, InventoryStockMovement::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direction' => 'The movement direction must be in or out.',
            ]);
        }

        return DB::transaction(function () use ($item, $type, $quantity, $direction, $actor, $meta): InventoryStockMovement {
            // Re-read under the transaction so two concurrent movements cannot
            // both compute their balance from the same stale number.
            $current = InventoryItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $balance = $direction === InventoryStockMovement::DIRECTION_IN
                ? bcadd($current->quantity, $quantity, 2)
                : bcsub($current->quantity, $quantity, 2);

            if (bccomp($balance, '0', 2) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$current->quantity} {$current->unit} of \"{$current->name}\" is in stock, so it cannot be reduced by {$quantity}.",
                ]);
            }

            $current->quantity = $balance;
            $current->updated_by = $actor->getKey();
            $current->save();

            $movement = InventoryStockMovement::create([
                'college_id' => $current->college_id,
                'item_id' => $current->getKey(),
                'purchase_order_id' => $meta['purchase_order_id'] ?? null,
                'type' => $type,
                'direction' => $direction,
                'quantity' => $quantity,
                'balance_after' => $balance,
                'unit_price' => $meta['unit_price'] ?? null,
                'reference' => $meta['reference'] ?? null,
                'reason' => $meta['reason'] ?? null,
                'notes' => $meta['notes'] ?? null,
                'movement_date' => $meta['movement_date'] ?? now()->toDateString(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record('inventory_stock.recorded', $movement, [], $movement->only(self::AUDITED));

            // Keep the caller's instance in step with what was written.
            $item->setRawAttributes($current->getAttributes(), true);

            return $movement;
        });
    }

    /**
     * Record the ledger row for a quantity that was set directly on the item
     * master — the opening balance of a newly recorded item, or a quantity
     * corrected on the item form.
     *
     * The item's on-hand value is already correct in that path, so ONLY the
     * movement is written. That keeps the ledger complete (every balance has a
     * reason) without counting the change twice, which is what `apply()` would
     * do. Returns null when the quantity did not actually move.
     */
    public function recordDirectBalance(
        InventoryItem $item,
        string $type,
        string $previousQuantity,
        User $actor,
        ?string $reason = null,
    ): ?InventoryStockMovement {
        $this->assertTenant($item);

        $delta = bcsub($item->quantity, $previousQuantity, 2);

        if (bccomp($delta, '0', 2) === 0) {
            return null;
        }

        $direction = bccomp($delta, '0', 2) > 0
            ? InventoryStockMovement::DIRECTION_IN
            : InventoryStockMovement::DIRECTION_OUT;

        return DB::transaction(function () use ($item, $type, $delta, $direction, $actor, $reason): InventoryStockMovement {
            $movement = InventoryStockMovement::create([
                'college_id' => $item->college_id,
                'item_id' => $item->getKey(),
                'purchase_order_id' => null,
                'type' => $type,
                'direction' => $direction,
                'quantity' => ltrim($delta, '-'),
                'balance_after' => $item->quantity,
                'unit_price' => null,
                'reference' => null,
                'reason' => $reason,
                'notes' => null,
                'movement_date' => now()->toDateString(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record('inventory_stock.recorded', $movement, [], $movement->only(self::AUDITED));

            return $movement;
        });
    }

    /**
     * The ledger of one item, newest first.
     *
     * @return Collection<int, InventoryStockMovement>
     */
    public function ledgerFor(InventoryItem $item, int $limit = 50): Collection
    {
        $this->assertTenant($item);

        return InventoryStockMovement::query()
            ->where('item_id', $item->getKey())
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The direction a movement type must use. An adjustment may go either way,
     * so its direction has to be stated; every other type has a fixed one.
     */
    private function resolveDirection(string $type, mixed $direction): string
    {
        $direction = is_string($direction) && $direction !== '' ? $direction : null;

        if (isset(InventoryStockMovement::DIRECTION_BY_TYPE[$type])) {
            $fixed = InventoryStockMovement::DIRECTION_BY_TYPE[$type];

            if ($direction !== null && $direction !== $fixed) {
                throw ValidationException::withMessages([
                    'direction' => "This movement type can only move stock {$fixed}.",
                ]);
            }

            return $fixed;
        }

        if (! in_array($direction, InventoryStockMovement::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direction' => 'Choose whether the correction increases or decreases stock.',
            ]);
        }

        return $direction;
    }

    /** Quantities are kept as exact decimal strings — bcadd avoids binary-float rounding. */
    private function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }

    private function optionalAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return bcadd((string) $value, '0', 2);
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(InventoryItem $item): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $item->college_id === (int) $collegeId, 403);
    }
}
