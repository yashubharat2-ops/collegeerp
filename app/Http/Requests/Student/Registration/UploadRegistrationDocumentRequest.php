<?php

namespace App\Http\Requests\Student\Registration;

use App\Domain\Student\Support\RegistrationStepRules;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attach (or replace) one document while the registration is a draft.
 *
 * The shape is checked here; the document-type limits (allowed extensions,
 * maximum size) and the storage path are enforced by
 * RegistrationDocumentStagingService, which is the only code that writes a file.
 */
class UploadRegistrationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = (int) app(TenantContext::class)->id();

        return [
            'document_type_id' => [
                'nullable', 'integer',
                Rule::exists('admission_document_types', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * A rejected upload sends the applicant back to the document step with the
     * error, not to whatever page the browser came from.
     *
     * @return string
     */
    protected function getRedirectUrl()
    {
        return route('students.registration.step', ['step' => RegistrationStepRules::STEP_DOCUMENTS]);
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to attach.',
            'file.mimes' => 'Attach a PDF, JPG, JPEG or PNG file.',
            'file.max' => 'The file may not be larger than 5 MB.',
            'document_type_id.exists' => 'Choose a document type that belongs to this college.',
        ];
    }
}
