<?php

namespace App\Http\Requests\EmployeeDocument;

use App\Models\EmployeeDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = EmployeeDocument::query()->find((int) $this->route('employee_document'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'document_name' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'document_type_id' => [
                'nullable',
                'integer',
                Rule::exists('admission_document_types', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_name' => $this->input('document_name') ?: ($this->input('title') ?: $this->input('name')),
            'document_type' => $this->input('document_type') ?: $this->input('type'),
        ]);

        // The employee and all storage/audit fields are immutable/server-owned.
        foreach ([
            'college_id', 'faculty_id', 'employee_id', 'file_path',
            'original_filename', 'mime_type', 'file_size', 'uploaded_by',
            'created_by', 'updated_by',
        ] as $key) {
            $this->request->remove($key);
        }
    }
}
