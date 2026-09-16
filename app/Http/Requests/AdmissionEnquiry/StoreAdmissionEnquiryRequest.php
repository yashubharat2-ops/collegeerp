<?php

namespace App\Http\Requests\AdmissionEnquiry;

use App\Models\AdmissionEnquiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionEnquiry::class) ?? false;
    }

    /**
     * Supports two modes:
     * - applicant_id provided: reuse existing applicant (same college)
     * - applicant_id null: create new minimal applicant with applicant_* fields
     *
     * college_id never from browser. academic_year_id, program_id, applicant_id validated
     * against current college via exists where college_id.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            // Existing applicant reuse
            'applicant_id' => ['nullable', 'integer', Rule::exists('admission_applicants', 'id')->where('college_id', $collegeId)],

            // New applicant fields (required when applicant_id is null)
            // Use required_without instead of required_if with null — required_if:applicant_id,null does not trigger when applicant_id is missing (not present), while required_without correctly requires first_name when applicant_id is not supplied.
            'applicant_first_name' => ['required_without:applicant_id', 'nullable', 'string', 'max:255'],
            'applicant_middle_name' => ['nullable', 'string', 'max:255'],
            'applicant_last_name' => ['nullable', 'string', 'max:255'],
            'applicant_email' => ['nullable', 'email', 'max:255'],
            'applicant_phone' => ['nullable', 'string', 'max:30'],
            'applicant_alternate_phone' => ['nullable', 'string', 'max:30'],
            'applicant_gender' => ['nullable', 'string', 'max:20', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'applicant_date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'applicant_address' => ['nullable', 'string', 'max:2000'],

            // Enquiry fields
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:new,contacted,followed_up,converted,closed,dropped'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'enquired_at' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date', 'after_or_equal:enquired_at'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('enquiry_number');
    }
}
