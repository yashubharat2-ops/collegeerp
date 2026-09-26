<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryMaintenance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update an existing Asset Maintenance record (Phase 3).
 *
 * A maintenance record is a live work order, so this is the correction path:
 * the status walks scheduled → in progress → completed, costs and dates are
 * filled in as the work happens. All fields are optional — only what the
 * form posts is changed. The record always stays linked to its existing
 * asset (`item_id` is not editable here; the link is made at creation).
 */
class UpdateInventoryMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $maintenance = $this->route('maintenance');

        return $maintenance instanceof InventoryMaintenance
            && ($this->user()?->can('update', $maintenance) ?? false);
    }

    public function ability(): ?string
    {
        return 'update';
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'item_id', 'created_by', 'updated_by', 'id'] as $field) {
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
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'maintenance_type' => ['sometimes', 'required', Rule::in(InventoryMaintenance::TYPES)],
            'status' => ['sometimes', 'required', Rule::in(InventoryMaintenance::STATUSES)],
            'vendor_id' => ['nullable', 'integer'],
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
            'vendor_id' => 'vendor',
            'maintenance_type' => 'maintenance type',
            'scheduled_on' => 'scheduled date',
            'completed_on' => 'completion date',
        ];
    }
}
