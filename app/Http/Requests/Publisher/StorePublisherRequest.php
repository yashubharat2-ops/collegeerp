<?php

namespace App\Http\Requests\Publisher;

use App\Models\Publisher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tenant-scoped publisher.
 *
 * college_id is stripped from the payload and stamped from the tenant context.
 * The name must be unique among the college's active publishers; that
 * comparison is case-insensitive and whitespace-insensitive, so it lives in
 * PublisherService (which owns the normalisation).
 */
class StorePublisherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Publisher::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'name_normalized'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(Publisher::STATUSES)],
        ];
    }
}
