<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryVendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped vendor.
 *
 * The vendor is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdateInventoryVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendor = InventoryVendor::query()->find($this->route('inventory_vendor'));

        if (! $vendor) {
            abort(404);
        }

        return $this->user()?->can('update', $vendor) ?? false;
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

        if (is_string($this->input('gst_number'))) {
            $merge['gst_number'] = strtoupper((string) preg_replace('/\s+/', '', trim($this->input('gst_number'))));
        }

        foreach (['contact_person', 'phone', 'email', 'address', 'gst_number'] as $field) {
            if (array_key_exists($field, $merge) && $merge[$field] === '') {
                $merge[$field] = null;
            } elseif ($this->has($field) && $this->input($field) === '') {
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
        $vendor = InventoryVendor::query()->find($this->route('inventory_vendor'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('inventory_vendors', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($vendor?->getKey()),
            ],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'required', Rule::in(InventoryVendor::STATUSES)],
        ];
    }
}
