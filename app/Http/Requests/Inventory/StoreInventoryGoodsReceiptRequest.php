<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryStockMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a Goods Receipt / Stock In movement.
 *
 * This is the Phase 2 final structure for "Goods Receipt / Stock In" — a manual
 * stock in (stock_in type) that raises on-hand quantity. Purchase receipts
 * (purchase_receipt type) are still booked through the Purchase Order receive
 * flow, but they are listed on the Goods Receipt screen as well, so the
 * architecture PO → Goods Receipt → Transactions → Current Stock is visible.
 *
 * The request only allows stock_in; adjustment and stock_out belong to the
 * Stock Adjustment module. Direction is fixed to "in" by the service, but the
 * form still posts it for consistency.
 */
class StoreInventoryGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $user->can('createGoodsReceipt', InventoryStockMovement::class)
            || $user->can('in', InventoryStockMovement::class);
    }

    public function ability(): ?string
    {
        return 'in';
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'balance_after', 'purchase_order_id', 'created_by', 'type', 'direction'] as $field) {
            // type and direction are forced server-side for this module
            if (in_array($field, ['type', 'direction'], true)) {
                continue;
            }
            $this->request->remove($field);
        }

        $merge = [
            'type' => InventoryStockMovement::TYPE_STOCK_IN,
            'direction' => InventoryStockMovement::DIRECTION_IN,
        ];

        if (is_string($this->input('reference'))) {
            $merge['reference'] = strtoupper(trim($this->input('reference')));
        }

        foreach (['reference', 'reason', 'notes', 'unit_price'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
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
            'type' => ['required', Rule::in([InventoryStockMovement::TYPE_STOCK_IN])],
            'direction' => ['required', Rule::in([InventoryStockMovement::DIRECTION_IN])],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
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
