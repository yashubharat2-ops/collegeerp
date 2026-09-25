<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Services\InventoryPurchaseOrderService;
use App\Models\InventoryPurchaseOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record a goods receipt against a purchase order.
 *
 * The order is resolved through CollegeScope and must be receivable (submitted
 * or partially received). A receipt row left blank means "nothing arrived on
 * that line", so quantities are optional per row — the service rejects a
 * receipt that would deliver more than a line's outstanding quantity, and
 * rejects an empty receipt outright.
 *
 * Status, college and audit columns are never taken from the payload: the
 * service derives the order's status from its lines.
 */
class ReceiveInventoryPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = InventoryPurchaseOrder::query()->find($this->route('purchase_order'));

        if (! $order) {
            abort(404);
        }

        return $this->user()?->can('receive', $order) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'status', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('reference'))) {
            $merge['reference'] = strtoupper(trim($this->input('reference')));
        }

        foreach (['reference', 'notes'] as $field) {
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
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*.line_id' => [
                'required',
                'integer',
                Rule::exists('inventory_purchase_order_items', 'id')
                    ->where('college_id', $collegeId),
            ],
            'receipts.*.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'movement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'receipts' => 'received quantities',
            'receipts.*.line_id' => 'order line',
            'receipts.*.quantity' => 'received quantity',
            'movement_date' => 'receipt date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $order = InventoryPurchaseOrder::query()->find($this->route('purchase_order'));

            if (! $order) {
                return;
            }

            // Cross-row rules: at least one quantity, every line belongs to
            // THIS order, and no line receives more than it is owed.
            app(InventoryPurchaseOrderService::class)->validateReceipts($order, (array) $this->input('receipts', []));
        });
    }
}
