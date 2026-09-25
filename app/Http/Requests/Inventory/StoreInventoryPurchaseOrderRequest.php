<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Services\InventoryPurchaseOrderService;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryVendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a tenant-scoped purchase order with its lines.
 *
 * college_id and the status are stripped from the payload: a new order is
 * always a draft of the ACTIVE college, and `total_amount` is computed from
 * the lines by the service — never accepted from the browser.
 *
 * The vendor and every ordered item must belong to the active college. The
 * cross-row rules (usable numbers, no item twice, at least one line) are
 * re-checked by the service so the HTTP messages and its guarantees cannot
 * drift apart.
 */
class StoreInventoryPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryPurchaseOrder::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'status', 'total_amount', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('number'))) {
            $merge['number'] = strtoupper(trim($this->input('number')));
        }

        foreach (['expected_date', 'notes'] as $field) {
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
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('inventory_purchase_orders', 'number')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'vendor_id' => [
                'required',
                'integer',
                Rule::exists('inventory_vendors', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'po_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:po_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('inventory_items', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'vendor_id' => 'vendor',
            'po_date' => 'order date',
            'expected_date' => 'expected date',
            'lines' => 'order lines',
            'lines.*.item_id' => 'item',
            'lines.*.quantity' => 'ordered quantity',
            'lines.*.unit_price' => 'unit price',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Let the field rules speak first: a missing vendor should not be
            // reported as a bad line.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Cross-row rules: at least one usable line, an item of the active
            // college per line, positive quantities, no item twice.
            app(InventoryPurchaseOrderService::class)->validateLines((array) $this->input('lines', []));
        });
    }
}
