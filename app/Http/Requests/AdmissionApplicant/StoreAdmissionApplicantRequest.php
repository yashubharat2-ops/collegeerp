<?php

namespace App\Http\Requests\AdmissionApplicant;

use App\Models\AdmissionApplicant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionApplicant::class) ?? false;
    }

    /**
     * college_id is never taken from browser: tenant is server-side context.
     * Validation is practical for college ERP: first_name required, last_name optional
     * to allow minimal walk-in enquiry, email/phone nullable but validated when present.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
    }
}
