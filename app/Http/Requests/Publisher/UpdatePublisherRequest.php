<?php

namespace App\Http\Requests\Publisher;

use App\Models\Publisher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped publisher.
 *
 * The publisher is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdatePublisherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $publisher = Publisher::query()->find($this->route('publisher'));

        if (! $publisher) {
            abort(404);
        }

        return $this->user()?->can('update', $publisher) ?? false;
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::in(Publisher::STATUSES)],
        ];
    }
}
