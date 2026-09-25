<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\InventoryItem;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderItem;
use App\Models\InventoryStockMovement;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryPurchaseOrderService — purchasing (Inventory / Asset Management,
 * Phase 2).
 *
 * A purchase order is a draft until it is submitted; only a draft may be
 * edited or deleted, because a submitted order is a promise to the vendor and
 * a received one is already reflected in the stock ledger. Receiving writes
 * stock movements (through InventoryStockService) and accumulates
 * `received_quantity` on each line, so an order can arrive in several
 * consignments and its status follows the lines rather than the user's say-so.
 *
 * What this service guarantees:
 *   - the order, its vendor and every ordered item belong to the ACTIVE
 *     college (re-checked here even if a Form Request were bypassed; the
 *     composite foreign keys enforce the same rule);
 *   - `number` is unique among the college's active orders;
 *   - `total_amount` is the server-computed sum of the line totals — it is
 *     never taken from request data;
 *   - an item appears at most once per order;
 *   - no line can be received beyond its outstanding quantity, and on-hand
 *     stock can never go negative;
 *   - status changes go through this service, so every transition is audited.
 */
class InventoryPurchaseOrderService
{
    private const DUPLICATE_NUMBER_MESSAGE = 'A purchase order with this number already exists for the active college.';

    private const AUDITED = [
        'vendor_id',
        'number',
        'po_date',
        'expected_date',
        'status',
        'total_amount',
        'notes',
    ];

    private const LINE_AUDITED = [
        'item_id',
        'quantity',
        'unit_price',
        'received_quantity',
    ];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly InventoryStockService $stock,
    ) {
    }

    /**
     * Create an order. It always starts as a draft: submitting is an explicit
     * step, so a half-typed order can never reach a vendor.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): InventoryPurchaseOrder
    {
        return DB::transaction(function () use ($college, $data, $actor): InventoryPurchaseOrder {
            $order = new InventoryPurchaseOrder([
                'college_id' => $college->getKey(),
                'vendor_id' => $data['vendor_id'],
                'number' => $data['number'],
                'po_date' => $data['po_date'],
                'status' => InventoryPurchaseOrder::STATUS_DRAFT,
                'total_amount' => '0.00',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $order->expected_date = $this->optionalDate($data['expected_date'] ?? null);
            $order->notes = $this->optionalText($data['notes'] ?? null);

            $this->assertVendorBelongsToCollege($order);
            $this->assertUniqueNumber($order);

            try {
                $order->save();
            } catch (QueryException $exception) {
                throw ValidationException::withMessages(['number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            $lines = $this->normaliseLines($data['lines'] ?? []);
            $this->assertLineRules($lines);
            $this->writeLines($order, $lines);

            $order->total_amount = $this->totalFor($order->lines()->get());
            $order->save();

            $this->audit->record('inventory_purchase_orders.created', $order, [], $order->only(self::AUDITED));

            return $order->refresh();
        });
    }

    /**
     * Update a draft: the header fields and the whole line set.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(InventoryPurchaseOrder $order, array $data, User $actor): InventoryPurchaseOrder
    {
        $this->assertTenant($order);

        if (! $order->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "A {$order->status} purchase order can no longer be edited. Cancel it and raise a new one.",
            ]);
        }

        return DB::transaction(function () use ($order, $data, $actor): InventoryPurchaseOrder {
            $old = $order->only(self::AUDITED);

            foreach (['vendor_id', 'number', 'po_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $order->{$field} = $data[$field];
                }
            }

            if (array_key_exists('expected_date', $data)) {
                $order->expected_date = $this->optionalDate($data['expected_date']);
            }

            if (array_key_exists('notes', $data)) {
                $order->notes = $this->optionalText($data['notes']);
            }

            $order->updated_by = $actor->getKey();

            $this->assertVendorBelongsToCollege($order);
            $this->assertUniqueNumber($order);

            try {
                $order->save();
            } catch (QueryException $exception) {
                throw ValidationException::withMessages(['number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            if (array_key_exists('lines', $data)) {
                $lines = $this->normaliseLines($data['lines'] ?? []);
                $this->assertLineRules($lines);
                $this->replaceLines($order, $lines);

                $order->total_amount = $this->totalFor($order->lines()->get());
                $order->save();
            }

            $this->audit->record('inventory_purchase_orders.updated', $order, $old, $order->only(self::AUDITED));

            return $order->refresh();
        });
    }

    /**
     * Soft delete a draft. Anything further along is part of the procurement
     * record (and may already be reflected in stock), so it is cancelled
     * rather than removed.
     */
    public function delete(InventoryPurchaseOrder $order, User $actor): void
    {
        $this->assertTenant($order);

        if (! $order->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "A {$order->status} purchase order cannot be deleted. Cancel it instead.",
            ]);
        }

        DB::transaction(function () use ($order): void {
            $snapshot = $order->only(self::AUDITED) + [
                'lines' => $order->lines()->get()->map->only(self::LINE_AUDITED)->all(),
            ];

            // Lines follow their header; a draft has no stock movements yet.
            $order->lines()->delete();
            $order->delete();

            $this->audit->record('inventory_purchase_orders.deleted', $order, $snapshot, []);
        });
    }

    /** Draft → submitted: the order goes out to the vendor and freezes. */
    public function submit(InventoryPurchaseOrder $order, User $actor): InventoryPurchaseOrder
    {
        $this->assertTenant($order);

        if (! $order->isSubmittable()) {
            throw ValidationException::withMessages([
                'status' => "Only a draft purchase order can be submitted; this one is {$order->status}.",
            ]);
        }

        if ($order->lines()->count() === 0) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one item before submitting the purchase order.',
            ]);
        }

        return $this->transition($order, InventoryPurchaseOrder::STATUS_SUBMITTED, $actor, 'inventory_purchase_orders.submitted');
    }

    /**
     * Call the order off. Anything already received stays received — the stock
     * ledger is never rewritten.
     */
    public function cancel(InventoryPurchaseOrder $order, User $actor): InventoryPurchaseOrder
    {
        $this->assertTenant($order);

        if (! $order->isCancellable()) {
            throw ValidationException::withMessages([
                'status' => "A {$order->status} purchase order cannot be cancelled.",
            ]);
        }

        return $this->transition($order, InventoryPurchaseOrder::STATUS_CANCELLED, $actor, 'inventory_purchase_orders.cancelled');
    }

    /**
     * Receive goods against the order (GRN).
     *
     * Each receipt line writes a stock movement and raises the item's on-hand
     * quantity; the order's status follows the lines (fully received →
     * received, otherwise partially received).
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(InventoryPurchaseOrder $order, array $data, User $actor): InventoryPurchaseOrder
    {
        $this->assertTenant($order);

        return DB::transaction(function () use ($order, $data, $actor): InventoryPurchaseOrder {
            $current = InventoryPurchaseOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-checked inside the lock: the request validated the status a
            // moment ago, but a concurrent receipt may have settled the order.
            $this->assertReceivable($current);

            $receipts = $this->normaliseReceipts($data['receipts'] ?? []);
            $lines = $current->lines()->get()->keyBy(fn (InventoryPurchaseOrderItem $line): int => (int) $line->getKey());

            $this->assertReceiptRules($receipts, $lines);

            $reference = $this->optionalText($data['reference'] ?? null);
            $movementDate = $this->optionalDate($data['movement_date'] ?? null) ?? now()->toDateString();
            $notes = $this->optionalText($data['notes'] ?? null);

            foreach ($receipts as $receipt) {
                $line = $lines->get($receipt['line_id']);
                $before = $line->only(self::LINE_AUDITED);

                // An item can be soft-deleted while its order is still open.
                // That must surface as a field error, not a 500 from the
                // ledger's own lookup, which only sees live items.
                $item = InventoryItem::withTrashed()->whereKey($line->item_id)->first();

                if (! $item || $item->trashed()) {
                    throw ValidationException::withMessages([
                        "receipts.{$receipt['index']}.line_id" => 'The item on this line has been deleted. Restore it before booking a receipt.',
                    ]);
                }

                $this->stock->apply(
                    $item,
                    InventoryStockMovement::TYPE_PURCHASE_RECEIPT,
                    $receipt['quantity'],
                    InventoryStockMovement::DIRECTION_IN,
                    $actor,
                    [
                        'purchase_order_id' => $current->getKey(),
                        'unit_price' => $line->unit_price,
                        'reference' => $reference,
                        'movement_date' => $movementDate,
                        'notes' => $notes,
                    ],
                );

                $line->received_quantity = bcadd($line->received_quantity, $receipt['quantity'], 2);
                $line->save();

                $this->audit->record('inventory_purchase_order_items.received', $line, $before, $line->only(self::LINE_AUDITED));
            }

            $old = $current->only(self::AUDITED);

            $current->status = $lines->every(fn (InventoryPurchaseOrderItem $line): bool => $line->isFullyReceived())
                ? InventoryPurchaseOrder::STATUS_RECEIVED
                : InventoryPurchaseOrder::STATUS_PARTIALLY_RECEIVED;
            $current->updated_by = $actor->getKey();
            $current->save();

            $this->audit->record('inventory_purchase_orders.received', $current, $old, $current->only(self::AUDITED));

            return $current->refresh();
        });
    }

    /**
     * Validate a set of order lines. Throws the first structural problem so
     * the UI can point at the offending row.
     *
     * Called by the Form Requests so the HTTP validation messages and this
     * service's own guarantees can never drift apart.
     *
     * @param  array<int, array>  $lines  Raw rows (before normalisation).
     */
    public function validateLines(array $lines): void
    {
        $this->assertLineRules($this->normaliseLines($lines));
    }

    /**
     * Validate a set of receipt rows against the order's own lines.
     *
     * Called from the receiving form request, so everything the service would
     * refuse is reported as a field error on the form rather than as an
     * exception after the fact. Whether the order may be received at all is
     * checked FIRST: a draft or an already settled order has nothing
     * outstanding either, and answering that with a per-line quantity error
     * would send the storekeeper to correct numbers that are not the problem.
     *
     * @param  array<int, array>  $receipts  Raw rows (before normalisation).
     */
    public function validateReceipts(InventoryPurchaseOrder $order, array $receipts): void
    {
        $this->assertReceivable($order);

        $lines = $order->lines()->get()->keyBy(fn (InventoryPurchaseOrderItem $line): int => (int) $line->getKey());

        $this->assertReceiptRules($this->normaliseReceipts($receipts), $lines);
    }

    /**
     * Only a submitted or partially received order accepts goods.
     */
    private function assertReceivable(InventoryPurchaseOrder $order): void
    {
        if (! $order->isReceivable()) {
            throw ValidationException::withMessages([
                'status' => "Only a submitted or partially received purchase order can be received; this one is {$order->status}.",
            ]);
        }
    }

    /**
     * @param  list<array{line_id: int, quantity: string}>  $receipts
     * @param  \Illuminate\Support\Collection<int, InventoryPurchaseOrderItem>  $lines
     */
    private function assertReceiptRules(array $receipts, $lines): void
    {
        if ($receipts === []) {
            throw ValidationException::withMessages([
                'receipts' => 'Enter the quantity received for at least one line.',
            ]);
        }

        foreach ($receipts as $receipt) {
            $line = $lines->get($receipt['line_id']);

            if (! $line) {
                throw ValidationException::withMessages([
                    "receipts.{$receipt['index']}.line_id" => 'That line does not belong to this purchase order.',
                ]);
            }

            if (bccomp($receipt['quantity'], $line->remainingQuantity(), 2) > 0) {
                throw ValidationException::withMessages([
                    "receipts.{$receipt['index']}.quantity" => "Only {$line->remainingQuantity()} of \"{$line->item?->name}\" is still outstanding on this order.",
                ]);
            }
        }
    }

    /**
     * Cross-row rules for order lines: the item exists in the active college,
     * quantities and prices are usable numbers, and an item appears once.
     *
     * @param  list<array{id: int|null, item_id: int|null, quantity: string|null, unit_price: string|null}>  $lines
     */
    private function assertLineRules(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one item to the purchase order.',
            ]);
        }

        $collegeId = app(TenantContext::class)->id();
        $seen = [];

        foreach ($lines as $index => $line) {
            if ($line['item_id'] === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => 'Choose the item for this line.',
                ]);
            }

            if (! InventoryItem::query()->whereKey($line['item_id'])->exists()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => 'The selected item does not belong to the active college.',
                ]);
            }

            if ($line['quantity'] === null || bccomp($line['quantity'], '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => 'The ordered quantity must be greater than zero.',
                ]);
            }

            if ($line['unit_price'] === null || bccomp($line['unit_price'], '0', 2) < 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.unit_price" => 'The unit price cannot be negative.',
                ]);
            }

            if (isset($seen[$line['item_id']])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.item_id" => 'This item is already on another line of the order.',
                ]);
            }

            $seen[$line['item_id']] = true;
        }
    }

    /**
     * Write the line set of a new order.
     *
     * @param  list<array>  $lines
     */
    private function writeLines(InventoryPurchaseOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $created = $order->lines()->create([
                'college_id' => $order->college_id,
                'item_id' => $line['item_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'received_quantity' => '0.00',
            ]);

            $this->audit->record('inventory_purchase_order_items.created', $created, [], $created->only(self::LINE_AUDITED));
        }
    }

    /**
     * Replace a draft's line set wholesale.
     *
     * The old lines go first, because an item may appear only once per order
     * and a re-used item would otherwise collide with the row on its way out.
     *
     * @param  list<array>  $lines
     */
    private function replaceLines(InventoryPurchaseOrder $order, array $lines): void
    {
        foreach ($order->lines()->get() as $existing) {
            $snapshot = $existing->only(self::LINE_AUDITED);
            $existing->delete();

            $this->audit->record('inventory_purchase_order_items.deleted', $existing, $snapshot, []);
        }

        $this->writeLines($order, $lines);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryPurchaseOrderItem>  $lines
     */
    private function totalFor($lines): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $total = bcadd($total, bcmul($line->quantity, $line->unit_price, 2), 2);
        }

        return $total;
    }

    private function transition(InventoryPurchaseOrder $order, string $status, User $actor, string $action): InventoryPurchaseOrder
    {
        return DB::transaction(function () use ($order, $status, $actor, $action): InventoryPurchaseOrder {
            $old = $order->only(self::AUDITED);

            $order->status = $status;
            $order->updated_by = $actor->getKey();
            $order->save();

            $this->audit->record($action, $order, $old, $order->only(self::AUDITED));

            return $order->refresh();
        });
    }

    /**
     * @param  array<int, array>  $lines
     * @return list<array{id: int|null, item_id: int|null, quantity: string|null, unit_price: string|null}>
     */
    private function normaliseLines(array $lines): array
    {
        $normalised = [];

        foreach (array_values($lines) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $itemId = $row['item_id'] ?? null;
            $quantity = $row['quantity'] ?? null;
            $unitPrice = $row['unit_price'] ?? null;

            $normalised[] = [
                'id' => isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null,
                'item_id' => $itemId === null || $itemId === '' ? null : (int) $itemId,
                'quantity' => $quantity === null || $quantity === '' ? null : bcadd((string) $quantity, '0', 2),
                'unit_price' => $unitPrice === null || $unitPrice === '' ? null : bcadd((string) $unitPrice, '0', 2),
            ];
        }

        return $normalised;
    }

    /**
     * @param  array<int, array>  $receipts
     * @return list<array{index: int, line_id: int, quantity: string}>
     */
    private function normaliseReceipts(array $receipts): array
    {
        $normalised = [];

        foreach (array_values($receipts) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $lineId = $row['line_id'] ?? null;
            $quantity = $row['quantity'] ?? null;

            // A row left blank on the receipt form is "nothing received", not
            // an error — only rows with a quantity are acted on.
            if ($lineId === null || $lineId === '' || $quantity === null || $quantity === '') {
                continue;
            }

            if (bccomp(bcadd((string) $quantity, '0', 2), '0', 2) <= 0) {
                continue;
            }

            $normalised[] = [
                'index' => $index,
                'line_id' => (int) $lineId,
                'quantity' => bcadd((string) $quantity, '0', 2),
            ];
        }

        return $normalised;
    }

    private function assertVendorBelongsToCollege(InventoryPurchaseOrder $order): void
    {
        $ok = InventoryVendor::withoutGlobalScopes()
            ->whereKey($order->vendor_id)
            ->where('college_id', $order->college_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages([
                'vendor_id' => 'The selected vendor does not belong to the active college.',
            ]);
        }
    }

    private function assertUniqueNumber(InventoryPurchaseOrder $order): void
    {
        $exists = InventoryPurchaseOrder::withoutGlobalScopes()
            ->where('college_id', $order->college_id)
            ->whereNull('deleted_at')
            ->where('number', $order->number)
            ->when($order->exists, fn ($query) => $query->whereKeyNot($order->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['number' => self::DUPLICATE_NUMBER_MESSAGE]);
        }
    }

    private function optionalDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(InventoryPurchaseOrder $order): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $order->college_id === (int) $collegeId, 403);
    }
}
