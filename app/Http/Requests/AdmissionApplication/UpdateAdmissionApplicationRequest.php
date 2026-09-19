<?php

namespace App\Http\Requests\AdmissionApplication;

use App\Models\AdmissionApplication;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionApplication::query()->find((int) $this->route('admission_application'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * applicant_id is immutable after creation: it is stripped here so an
     * application can never be re-pointed at another person via the browser,
     * preserving the applicant history chain. Academic year, program, enquiry
     * link, status and remarks may be corrected, always within the same college.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)],
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
        $this->request->remove('applicant_id');
    }
}
