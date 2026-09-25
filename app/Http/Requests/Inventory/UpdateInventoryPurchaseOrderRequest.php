<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Services\InventoryPurchaseOrderService;
use App\Models\InventoryPurchaseOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Update a tenant-scoped purchase order.
 *
 * The order is resolved through CollegeScope, so a foreign-tenant id can never
 * be updated here, and the service refuses to touch anything that is no
 * longer a draft. Status, total and audit columns are never taken from the
 * payload.
 */
class UpdateInventoryPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = InventoryPurchaseOrder::query()->find($this->route('purchase_order'));

        if (! $order) {
            abort(404);
        }

        return $this->user()?->can('update', $order) ?? false;
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
                    ->whereNull('deleted_at')
                    ->ignore($this->route('purchase_order')),
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
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            app(InventoryPurchaseOrderService::class)->validateLines((array) $this->input('lines', []));
        });
    }
}
