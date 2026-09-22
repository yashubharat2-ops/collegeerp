<?php

namespace App\Http\Requests\EmployeeDocument;

use App\Models\EmployeeDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeDocument::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'faculty_id' => [
                'required',
                'integer',
                Rule::exists('faculties', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'document_name' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'document_type_id' => [
                'nullable',
                'integer',
                Rule::exists('admission_document_types', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $facultyId = $this->input('faculty_id') ?: $this->input('employee_id');
        $documentName = $this->input('document_name') ?: ($this->input('title') ?: $this->input('name'));
        $documentType = $this->input('document_type') ?: $this->input('type');

        $this->merge([
            'faculty_id' => $facultyId,
            'document_name' => $documentName,
            'document_type' => $documentType,
        ]);

        // Tenant, storage metadata and audit principals are server-owned.
        foreach ([
            'college_id', 'employee_id', 'file_path', 'original_filename',
            'mime_type', 'file_size', 'uploaded_by', 'created_by', 'updated_by',
        ] as $key) {
            $this->request->remove($key);
        }
    }
}
