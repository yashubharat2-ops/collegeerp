<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHostelFeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('fee');
        if (is_string($assignment)) {
            $assignment = HostelFeeAssignment::query()->find($assignment);
        }
        if (! $assignment) {
            return false;
        }
        return $this->user()?->can('update', $assignment) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'hostel_allocation_id', 'hostel_fee_structure_id', 'academic_year_id', 'assigned_amount', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('remarks'))) {
            $this->merge(['remarks' => trim($this->input('remarks'))]);
        }
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['sometimes', 'required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['sometimes', Rule::in(HostelFeeAssignment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
