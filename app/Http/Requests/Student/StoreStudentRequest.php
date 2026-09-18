<?php

namespace App\Http\Requests\Student;

use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    /**
     * college_id and student_number are never taken from the browser: the
     * tenant is the server-side context and the number is generated
     * transactionally via GenerateStudentNumber.
     *
     * admission_application_id is NOT accepted here: the provenance link is
     * owned exclusively by ConvertApplicationToStudent, so an application can
     * only ever be linked to a student through the status-checked conversion
     * workflow.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('students', 'phone')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'admission_date' => ['nullable', 'date'],
            'address_line_1' => ['nullable', 'string', 'max:2000'],
            'address_line_2' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(Student::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('student_number');
        $this->request->remove('admission_application_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
