<?php

namespace App\Http\Requests\SalaryStructure;

use App\Models\SalaryStructure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalaryStructureRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', SalaryStructure::class) ?? false; }
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('salary_structures', 'code')->where('college_id', $collegeId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::in(SalaryStructure::STATUSES)],
        ];
    }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        $this->request->remove('college_id');
    }
}
