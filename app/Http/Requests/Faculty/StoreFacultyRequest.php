<?php

namespace App\Http\Requests\Faculty;

use App\Models\Faculty;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Faculty::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'employee_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('faculties', 'employee_code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'designation' => ['nullable', 'string', 'max:100'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'employment_type' => ['nullable', 'string', 'max:50', Rule::in(Faculty::EMPLOYMENT_TYPES)],
            'status' => ['required', 'in:active,inactive'],
            'joining_date' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
