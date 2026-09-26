<?php

namespace App\Http\Requests\Inventory;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record an Asset Return (Inventory / Asset Management, Phase 3).
 *
 * A return flips an ACTIVE assignment of the active college to `returned`
 * with the return date, the acting user and optional notes. The assignment
 * row itself is never deleted — its history (who held the asset, since
 * when, who took it back) is preserved. `assignment_id` must point at an
 * existing same-tenant assignment; the service answers with a field error
 * when the asset is already returned.
 */
class StoreInventoryAssetReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Direct slug check (not can('return', <class>)): the return ability
        // answers per-assignment in the policy, and the controller re-checks
        // it against the loaded instance after validation.
        return $this->user()?->hasPermission('inventory_asset_returns.create') ?? false;
    }

    public function ability(): ?string
    {
        return 'returnAsset';
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'returned_by', 'status', 'id'] as $field) {
            $this->request->remove($field);
        }

        if ($this->has('return_notes') && $this->input('return_notes') === '') {
            $this->merge(['return_notes' => null]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'assignment_id' => [
                'required',
                'integer',
                Rule::exists('inventory_assignments', 'id')
                    ->where('college_id', $collegeId),
            ],
            'returned_on' => ['required', 'date'],
            'return_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'assignment_id' => 'assignment',
            'returned_on' => 'return date',
        ];
    }
}
