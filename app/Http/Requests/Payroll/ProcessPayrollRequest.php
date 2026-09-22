<?php

namespace App\Http\Requests\Payroll;

use App\Models\Payroll;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessPayrollRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('process', Payroll::class) ?? false; }
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        return [
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'salary_structure_id' => ['required', 'integer', Rule::exists('salary_structures', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'pay_period' => ['required', 'date_format:Y-m'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
    protected function prepareForValidation(): void { foreach (['college_id', 'status', 'processed_at', 'processed_by', 'created_by', 'updated_by'] as $key) $this->request->remove($key); }
}
