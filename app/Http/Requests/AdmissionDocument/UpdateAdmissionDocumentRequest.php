<?php

namespace App\Http\Requests\AdmissionDocument;

use App\Models\AdmissionDocument;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdmissionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionDocument::query()->find((int) $this->route('admission_document'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('file_path');
        $this->request->remove('verification_status');
        $this->request->remove('applicant_id');
        $this->request->remove('application_id');
        $this->request->remove('document_type_id');
    }
}
