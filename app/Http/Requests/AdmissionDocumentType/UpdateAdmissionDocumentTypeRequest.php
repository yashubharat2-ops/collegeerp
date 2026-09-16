<?php

namespace App\Http\Requests\AdmissionDocumentType;

use App\Models\AdmissionDocumentType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionDocumentType::query()->find((int) $this->route('admission_document_type'));
        if (! $model) {
            $model = AdmissionDocumentType::query()->find((int) $this->route('document_type'));
        }
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $id = $this->route('admission_document_type') ?? $this->route('document_type');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('admission_document_types')->where('college_id', $collegeId)->ignore($id)],
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
