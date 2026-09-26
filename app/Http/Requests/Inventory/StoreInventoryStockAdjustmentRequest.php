<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryStockMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a Stock Adjustment (correction or stock out).
 *
 * Final Phase 2 structure: Stock Adjustment must generate an inventory
 * transaction. It covers both:
 *  - adjustment (in or out, with reason, direction chosen)
 *  - stock_out (out only, with reason)
 *
 * Both lower or raise the on-hand quantity and write a ledger row with
 * balance_after, so every balance has a reason.
 */
class StoreInventoryStockAdjustmentRequest extends FormRequest
{
    public const ALLOWED_TYPES = [
        InventoryStockMovement::TYPE_ADJUSTMENT,
        InventoryStockMovement::TYPE_STOCK_OUT,
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $user->can('createAdjustment', InventoryStockMovement::class)
            || $user->can('adjust', InventoryStockMovement::class)
            || $user->can('out', InventoryStockMovement::class);
    }

    public function ability(): ?string
    {
        return match ((string) $this->validated('type')) {
            InventoryStockMovement::TYPE_STOCK_OUT => 'out',
            InventoryStockMovement::TYPE_ADJUSTMENT => 'adjust',
            default => null,
        };
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'balance_after', 'purchase_order_id', 'created_by'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('reference'))) {
            $merge['reference'] = strtoupper(trim($this->input('reference')));
        }

        foreach (['reference', 'reason', 'notes', 'unit_price'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('inventory_items', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::in(self::ALLOWED_TYPES)],
            'direction' => ['required', Rule::in(InventoryStockMovement::DIRECTIONS)],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'movement_date' => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_id' => 'item',
            'unit_price' => 'unit price',
            'movement_date' => 'movement date',
        ];
    }
}
