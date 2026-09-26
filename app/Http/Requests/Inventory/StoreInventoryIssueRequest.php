<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryIssue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record an Item Issue / Allocation (Inventory / Asset Management, Phase 3).
 *
 * The stock itself is reduced by the service through the Phase 2 ledger
 * (a `stock_out` movement); this request only validates the allocation
 * behind it. The item must be a consumable of the active college — the
 * service answers with the item-type error; `number`, `created_by` and
 * `college_id` are server-controlled and stripped from the payload.
 */
class StoreInventoryIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryIssue::class) ?? false;
    }

    public function ability(): ?string
    {
        return 'create';
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'number', 'id'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('reference'))) {
            $this->merge(['reference' => strtoupper(trim($this->input('reference')))]);
        }

        foreach (['purpose', 'reference', 'notes'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $recipientTable = match ((string) $this->input('issued_to_type')) {
            'student' => 'students',
            'faculty' => 'faculties',
            default => 'students',
        };

        return [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('inventory_items', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'issued_to_type' => ['required', Rule::in(InventoryIssue::RECIPIENT_TYPES)],
            'issued_to_id' => [
                'required',
                'integer',
                Rule::exists($recipientTable, 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'purpose' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'movement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_id' => 'item',
            'issued_to_type' => 'recipient type',
            'issued_to_id' => 'recipient',
            'movement_date' => 'issue date',
        ];
    }
}
