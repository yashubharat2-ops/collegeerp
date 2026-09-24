<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped item category.
 *
 * The category is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdateInventoryCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = InventoryCategory::query()->find($this->route('inventory_category'));

        if (! $category) {
            abort(404);
        }

        return $this->user()?->can('update', $category) ?? false;
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

        if ($this->has('description') && $this->input('description') === '') {
            $merge['description'] = null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $category = InventoryCategory::query()->find($this->route('inventory_category'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('inventory_categories', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($category?->getKey()),
            ],
            'status' => ['sometimes', 'required', Rule::in(InventoryCategory::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
