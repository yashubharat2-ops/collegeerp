<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryItemService — CRUD for the single item / asset master.
 *
 * Consumables and assets are not separate catalogues. What this service
 * guarantees is that a saved row is structurally sound:
 *
 *   - the item and its category belong to the ACTIVE college (a forged
 *     cross-tenant category id is rejected even if the Form Request were
 *     bypassed; the composite foreign key enforces the same rule)
 *   - `code` is unique among the college's active (not soft-deleted) items
 *   - `serial_number`, when present, is unique among the college's active items
 *   - college_id, created_by and updated_by are server-controlled
 *
 * Phase 2 addition: the stock ledger is the reason behind every balance, so an
 * opening quantity and any quantity corrected here are written to
 * `inventory_stock_movements` (through InventoryStockService) in the same
 * transaction. The item form still works exactly as it did; it just no longer
 * leaves an unexplained number behind.
 */
class InventoryItemService
{
    private const DUPLICATE_CODE_MESSAGE = 'An item with this code already exists for the active college.';

    private const DUPLICATE_SERIAL_MESSAGE = 'An item with this serial number already exists for the active college.';

    private const AUDITED = [
        'name',
        'code',
        'category_id',
        'item_type',
        'brand',
        'model',
        'serial_number',
        'unit',
        'quantity',
        'status',
        'description',
    ];

    /** Optional scalar fields where an empty form input means "not recorded". */
    private const OPTIONAL = ['brand', 'model', 'serial_number', 'description'];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly InventoryStockService $stock,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): InventoryItem
    {
        return DB::transaction(function () use ($college, $data, $actor): InventoryItem {
            $item = new InventoryItem([
                'college_id' => $college->getKey(),
                'category_id' => $data['category_id'],
                'name' => trim((string) $data['name']),
                'code' => $data['code'],
                'item_type' => $data['item_type'],
                'unit' => trim((string) $data['unit']),
                'quantity' => $this->quantity($data['quantity']),
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $item->{$field} = $this->optional($data[$field] ?? null);
            }

            $this->assertCategoryBelongsToCollege($item);
            $this->assertUniqueIdentifiers($item);

            try {
                $item->save();
            } catch (QueryException $exception) {
                throw ValidationException::withMessages($this->constraintMessages($exception));
            }

            $this->audit->record('inventory_items.created', $item, [], $item->only(self::AUDITED));

            // Phase 2: the stock ledger is the reason behind every balance, so
            // an opening quantity is recorded as an opening movement rather
            // than left as an unexplained number.
            $this->stock->recordDirectBalance($item, InventoryStockMovement::TYPE_OPENING, '0.00', $actor);

            return $item->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InventoryItem $item, array $data, User $actor): InventoryItem
    {
        $this->assertTenant($item);

        return DB::transaction(function () use ($item, $data, $actor): InventoryItem {
            $old = $item->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $item->{$field} = match (true) {
                    $field === 'name', $field === 'unit' => trim((string) $data[$field]),
                    $field === 'quantity' => $this->quantity($data[$field]),
                    in_array($field, self::OPTIONAL, true) => $this->optional($data[$field]),
                    default => $data[$field],
                };
            }

            $item->updated_by = $actor->getKey();

            $this->assertCategoryBelongsToCollege($item);
            $this->assertUniqueIdentifiers($item);

            try {
                $item->save();
            } catch (QueryException $exception) {
                throw ValidationException::withMessages($this->constraintMessages($exception));
            }

            $this->audit->record('inventory_items.updated', $item, $old, $item->only(self::AUDITED));

            // Phase 2: a quantity corrected on the item form would otherwise
            // leave the ledger unable to explain the balance, so the
            // difference is written as an adjustment.
            $this->stock->recordDirectBalance(
                $item,
                InventoryStockMovement::TYPE_ADJUSTMENT,
                (string) ($old['quantity'] ?? '0.00'),
                $actor,
                'Corrected on the item form',
            );

            return $item->refresh();
        });
    }

    public function delete(InventoryItem $item, User $actor): void
    {
        $this->assertTenant($item);

        DB::transaction(function () use ($item): void {
            $snapshot = $item->only(self::AUDITED);
            $item->delete();

            $this->audit->record('inventory_items.deleted', $item, $snapshot, []);
        });
    }

    private function assertCategoryBelongsToCollege(InventoryItem $item): void
    {
        $ok = InventoryCategory::withoutGlobalScopes()
            ->whereKey($item->category_id)
            ->where('college_id', $item->college_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages([
                'category_id' => 'The selected category does not belong to the active college.',
            ]);
        }
    }

    private function assertUniqueIdentifiers(InventoryItem $item): void
    {
        $base = fn () => InventoryItem::withoutGlobalScopes()
            ->where('college_id', $item->college_id)
            ->whereNull('deleted_at')
            ->when($item->exists, fn ($query) => $query->whereKeyNot($item->getKey()));

        if ($base()->where('code', $item->code)->exists()) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }

        if ($item->serial_number !== null && $base()->where('serial_number', $item->serial_number)->exists()) {
            throw ValidationException::withMessages(['serial_number' => self::DUPLICATE_SERIAL_MESSAGE]);
        }
    }

    /**
     * Map a racing unique-index rejection back to the field that collided.
     *
     * @return array<string, string>
     */
    private function constraintMessages(QueryException $exception): array
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'serial')) {
            return ['serial_number' => self::DUPLICATE_SERIAL_MESSAGE];
        }

        return ['code' => self::DUPLICATE_CODE_MESSAGE];
    }

    private function quantity(mixed $value): string
    {
        // bcadd avoids binary-float rounding on quantities such as 0.10.
        return bcadd((string) $value, '0', 2);
    }

    private function optional(mixed $value): ?string
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
