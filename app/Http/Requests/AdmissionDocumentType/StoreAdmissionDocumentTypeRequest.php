<?php

namespace App\Http\Requests\AdmissionDocumentType;

use App\Models\AdmissionDocumentType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionDocumentType::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('admission_document_types')->where('college_id', $collegeId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['nullable', 'boolean'],
            'allowed_extensions' => ['nullable', 'string', 'max:255'],
            'allowed_mimes' => ['nullable', 'string', 'max:500'],
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:51200'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
    }
}
