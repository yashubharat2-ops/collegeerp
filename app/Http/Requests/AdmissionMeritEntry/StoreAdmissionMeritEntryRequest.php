<?php

namespace App\Http\Requests\AdmissionMeritEntry;

use App\Models\AdmissionMeritEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionMeritEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionMeritEntry::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'merit_list_id' => ['required', 'integer', Rule::exists('admission_merit_lists', 'id')->where('college_id', $collegeId)],
            'application_id' => ['required', 'integer', Rule::exists('admission_applications', 'id')->where('college_id', $collegeId)],
            'merit_score' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'selection_status' => ['required', 'in:pending,selected,waitlisted,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('applicant_id');
    }
}
