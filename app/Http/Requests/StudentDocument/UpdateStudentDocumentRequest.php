<?php

namespace App\Http\Requests\StudentDocument;

use App\Models\StudentDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's document 404s here.
        $model = StudentDocument::query()->find((int) $this->route('student_document'));

        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * student_id is immutable. A replacement file is optional; when one is
     * supplied the service re-stores it under a fresh server-generated path and
     * resets verification, so a replaced document is never silently "verified".
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'document_type_id' => ['nullable', 'integer', Rule::exists('admission_document_types', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('student_id');
        $this->request->remove('file_path');
        $this->request->remove('original_filename');
        $this->request->remove('mime_type');
        $this->request->remove('file_size');
        $this->request->remove('verification_status');
        $this->request->remove('verified_by');
        $this->request->remove('verified_at');
        $this->request->remove('uploaded_by');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
