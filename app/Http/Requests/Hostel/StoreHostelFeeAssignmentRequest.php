<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHostelFeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelFeeAssignment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'academic_year_id', 'assigned_amount', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('remarks'))) {
            $this->merge(['remarks' => trim($this->input('remarks'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'hostel_allocation_id' => [
                'required',
                'integer',
                Rule::exists('hostel_allocations', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'hostel_fee_structure_id' => [
                'required',
                'integer',
                Rule::exists('hostel_fee_structures', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['sometimes', Rule::in(HostelFeeAssignment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
