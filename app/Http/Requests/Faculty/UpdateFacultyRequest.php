<?php

namespace App\Http\Requests\Faculty;

use App\Models\Faculty;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacultyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = Faculty::query()->find((int) $this->facultyRouteKey());
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->facultyRouteKey();

        return [
            'employee_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('faculties', 'employee_code')
                    ->where('college_id', $collegeId)
                    ->ignore($ignoreId),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'alternate_phone' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'designation' => ['nullable', 'string', 'max:100'],
            'designation_id' => [
                'nullable',
                'integer',
                Rule::exists('designations', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
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
            'employment_end_date' => ['nullable', 'date', 'after_or_equal:joining_date'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('employee_code'))) {
            $this->merge(['employee_code' => strtoupper(trim($this->input('employee_code')))]);
        }

        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }

    private function facultyRouteKey(): string
    {
        return (string) ($this->route('faculty')
            ?? $this->route('employee')
            ?? $this->route('staff'));
    }
}
