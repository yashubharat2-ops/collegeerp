<?php

namespace App\Http\Requests\AdmissionDocument;

use App\Models\AdmissionDocument;
use Illuminate\Foundation\Http\FormRequest;

class VerifyAdmissionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionDocument::query()->find((int) $this->route('admission_document'));
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
