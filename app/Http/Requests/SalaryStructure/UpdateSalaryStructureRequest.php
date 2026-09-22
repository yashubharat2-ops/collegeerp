<?php

namespace App\Http\Requests\SalaryStructure;

use App\Models\SalaryStructure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = SalaryStructure::query()->find((int) $this->route('salary_structure'));
        if (! $model) abort(404);
        return $this->user()?->can('update', $model) ?? false;
    }
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('salary_structures', 'code')->where('college_id', $collegeId)->ignore((int) $this->route('salary_structure'))],
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
