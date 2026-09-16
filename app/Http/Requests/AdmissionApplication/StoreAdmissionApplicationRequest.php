<?php

namespace App\Http\Requests\AdmissionApplication;

use App\Models\AdmissionApplication;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionApplication::class) ?? false;
    }

    /**
     * college_id, application_number and submitted_at are never taken from the
     * browser: the tenant is the server-side context, the number is generated
     * transactionally, and submission time is stamped server-side.
     *
     * applicant_id, academic_year_id and program_id are required; enquiry_id
     * stays optional because an application may originate without an enquiry.
     * Every FK must belong to the active college.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'applicant_id' => ['required', 'integer', Rule::exists('admission_applicants', 'id')->where('college_id', $collegeId)],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)],
            'enquiry_id' => ['nullable', 'integer', Rule::exists('admission_enquiries', 'id')->where('college_id', $collegeId)],
            'status' => ['required', 'in:draft,submitted,under_review,approved,rejected,cancelled,admitted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('application_number');
        $this->request->remove('submitted_at');
    }
}
