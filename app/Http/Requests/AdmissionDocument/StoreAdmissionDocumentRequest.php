<?php

namespace App\Http\Requests\AdmissionDocument;

use App\Models\AdmissionDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionDocument::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'applicant_id' => ['required', 'integer', Rule::exists('admission_applicants', 'id')->where('college_id', $collegeId)],
            'application_id' => ['nullable', 'integer', Rule::exists('admission_applications', 'id')->where('college_id', $collegeId)],
            'document_type_id' => ['required', 'integer', Rule::exists('admission_document_types', 'id')->where('college_id', $collegeId)],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('file_path');
        $this->request->remove('verification_status');
        $this->request->remove('verified_by');
        $this->request->remove('verified_at');
    }
}
