<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped item / asset.
 *
 * The item is resolved through CollegeScope, so a foreign-tenant id can never
 * be updated here. The category, when changed, must still belong to the
 * active college.
 */
class UpdateInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = InventoryItem::query()->find($this->route('inventory_item'));

        if (! $item) {
            abort(404);
        }

        return $this->user()?->can('update', $item) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('name'))) {
            $merge['name'] = trim($this->input('name'));
        }

        if (is_string($this->input('code'))) {
            $merge['code'] = strtoupper(trim($this->input('code')));
        }

        if (is_string($this->input('unit'))) {
            $merge['unit'] = trim($this->input('unit'));
        }

        if ($this->has('serial_number')) {
            $serial = is_string($this->input('serial_number')) ? strtoupper(trim($this->input('serial_number'))) : $this->input('serial_number');
            $merge['serial_number'] = $serial === '' ? null : $serial;
        }

        foreach (['brand', 'model', 'description'] as $field) {
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
        $item = InventoryItem::query()->find($this->route('inventory_item'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('inventory_items', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($item?->getKey()),
            ],
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('inventory_categories', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'item_type' => ['sometimes', 'required', Rule::in(InventoryItem::TYPES)],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('inventory_items', 'serial_number')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($item?->getKey()),
            ],
            'unit' => ['sometimes', 'required', 'string', 'max:30'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'required', Rule::in(InventoryItem::STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'item_type' => 'item type',
            'serial_number' => 'serial number',
        ];
    }
}
