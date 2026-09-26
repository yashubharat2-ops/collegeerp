<?php

namespace App\Http\Requests\Inventory;

use App\Models\InventoryAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record an Asset Assignment (Inventory / Asset Management, Phase 3).
 *
 * The asset must be an `item_type = 'asset'` row of the active college —
 * the service answers with the item-type error and enforces the
 * one-active-assignment rule under a row lock. The assignee is an existing,
 * same-tenant student or staff member (the `faculties` table, which also
 * backs the HR Employee alias). `college_id`, `created_by` and `status` are
 * server-controlled and stripped from the payload.
 */
class StoreInventoryAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryAssignment::class) ?? false;
    }

    public function ability(): ?string
    {
        return 'create';
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'status', 'returned_on', 'returned_by', 'return_notes', 'id'] as $field) {
            $this->request->remove($field);
        }

        if ($this->has('purpose') && $this->input('purpose') === '') {
            $this->merge(['purpose' => null]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $assigneeTable = match ((string) $this->input('assigned_to_type')) {
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
            'assigned_to_type' => ['required', Rule::in(InventoryAssignment::ASSIGNEE_TYPES)],
            'assigned_to_id' => [
                'required',
                'integer',
                Rule::exists($assigneeTable, 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'purpose' => ['nullable', 'string', 'max:255'],
            'assigned_on' => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_id' => 'asset',
            'assigned_to_type' => 'assignee type',
            'assigned_to_id' => 'assignee',
            'assigned_on' => 'assignment date',
        ];
    }
}
