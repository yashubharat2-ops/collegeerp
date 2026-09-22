<?php

namespace App\Http\Requests\SalaryComponent;

use App\Models\SalaryComponent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalaryComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = SalaryComponent::query()->find((int) $this->route('salary_component'));
        if (! $model) abort(404);
        return $this->user()?->can('update', $model) ?? false;
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'component_type' => ['required', Rule::in(SalaryComponent::COMPONENT_TYPES)],
            'calculation_type' => ['required', Rule::in(SalaryComponent::CALCULATION_TYPES)],
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'basis' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        $this->request->remove('college_id');
    }
}
