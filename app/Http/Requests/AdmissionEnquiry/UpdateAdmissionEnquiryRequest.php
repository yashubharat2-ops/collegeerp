<?php

namespace App\Http\Requests\AdmissionEnquiry;

use App\Models\AdmissionEnquiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionEnquiry::query()->find((int) $this->route('admission_enquiry'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
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
        $this->request->remove('applicant_id');
    }
}
