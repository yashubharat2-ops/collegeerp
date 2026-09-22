<?php

namespace App\Http\Requests\Transport;

use App\Models\VehicleDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vehicle document metadata update (file replacement optional).
 *
 * The same boundary rules as the upload: actor/path fields are stripped and
 * the vehicle must belong to the active college.
 */
class UpdateVehicleDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's document 404s here.
        $record = VehicleDocument::query()->find((int) $this->route('vehicle_document'));

        if (! $record) {
            abort(404);
        }

        return $this->user()?->can('update', $record) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'file_path', 'original_filename', 'mime_type', 'file_size', 'uploaded_by', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        return [
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'document_type' => ['required', 'string', 'max:100'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'expiry_date.after_or_equal' => 'The expiry date must not be before the issue date.',
        ];
    }
}
