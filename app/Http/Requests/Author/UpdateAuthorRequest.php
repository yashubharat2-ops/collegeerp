<?php

namespace App\Http\Requests\Author;

use App\Models\Author;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped author.
 *
 * The author is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdateAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $author = Author::query()->find($this->route('author'));

        if (! $author) {
            abort(404);
        }

        return $this->user()?->can('update', $author) ?? false;
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
            'status' => ['sometimes', 'required', Rule::in(Author::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
