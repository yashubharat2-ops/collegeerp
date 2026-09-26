<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryMaintenance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a new Asset Maintenance (Inventory / Asset Management, Phase 3).
 *
 * The record must link to an EXISTING asset of the active college (the
 * service answers with the item-type error for consumables) and, when a
 * vendor is given, to an existing same-tenant vendor from the Phase 1
 * master. `college_id`, `created_by` and `updated_by` are server-controlled
 * and stripped from the payload.
 */
class StoreInventoryMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryMaintenance::class) ?? false;
    }

    public function ability(): ?string
    {
        return 'create';
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'id'] as $field) {
            $this->request->remove($field);
        }

        foreach (['vendor_id', 'scheduled_on', 'completed_on', 'cost', 'performed_by', 'description'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
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
            'vendor_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_vendors', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'maintenance_type' => ['required', Rule::in(InventoryMaintenance::TYPES)],
            'status' => ['required', Rule::in(InventoryMaintenance::STATUSES)],
            'scheduled_on' => ['nullable', 'date'],
            'completed_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_id' => 'asset',
            'vendor_id' => 'vendor',
            'maintenance_type' => 'maintenance type',
            'scheduled_on' => 'scheduled date',
            'completed_on' => 'completion date',
        ];
    }
}
