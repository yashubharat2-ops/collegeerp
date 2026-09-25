<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryStockMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a manual stock movement (stock in, stock out or a correction).
 *
 * college_id, the resulting balance and the audit columns are never taken from
 * the payload: the service reads the item's current on-hand quantity inside a
 * transaction and writes `balance_after` itself.
 *
 * The permission checked depends on the movement type — stock in, stock out
 * and corrections are separately grantable, so a storekeeper can be allowed to
 * receive stock without being allowed to write it off.
 */
class StoreInventoryStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $ability = match ((string) $this->input('type', '')) {
            InventoryStockMovement::TYPE_STOCK_IN => 'in',
            InventoryStockMovement::TYPE_STOCK_OUT => 'out',
            InventoryStockMovement::TYPE_ADJUSTMENT => 'adjust',
            default => null,
        };

        return $ability !== null && $user->can($ability, InventoryStockMovement::class);
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
            'type' => ['required', Rule::in(InventoryStockMovement::MANUAL_TYPES)],
            // A stock in only ever goes in and a stock out only ever goes out;
            // a correction has to say which way. The service enforces the same
            // pairing, so a mismatched pair cannot slip through.
            'direction' => ['required', Rule::in(InventoryStockMovement::DIRECTIONS)],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'required_if:type,stock_out,adjustment', 'string', 'max:255'],
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
