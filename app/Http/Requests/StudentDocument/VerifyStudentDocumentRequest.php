<?php

namespace App\Http\Requests\StudentDocument;

use App\Models\StudentDocument;
use Illuminate\Foundation\Http\FormRequest;

class VerifyStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's document 404s here.
        $model = StudentDocument::query()->find((int) $this->route('student_document'));

        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('verify', $model) ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:verify,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'rejection_remarks' => ['required_if:action,reject', 'nullable', 'string', 'max:2000'],
        ];
    }
}
