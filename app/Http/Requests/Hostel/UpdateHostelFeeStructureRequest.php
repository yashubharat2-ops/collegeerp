<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelFeeStructure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHostelFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('fee_structure');
        if (is_string($record)) {
            $record = HostelFeeStructure::query()->find($record);
        }
        if (! $record) {
            return false;
        }
        return $this->user()?->can('update', $record) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $id = $this->route('fee_structure');

        return [
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_fee_structures', 'code')->where('college_id', $collegeId)->ignore($id),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'lte:9999999999.99'],
            'frequency' => ['nullable', 'string', 'max:50', Rule::in(HostelFeeStructure::FREQUENCIES)],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::in(HostelFeeStructure::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
