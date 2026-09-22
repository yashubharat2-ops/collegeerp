<?php

namespace App\Http\Requests\Author;

use App\Models\Author;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tenant-scoped author.
 *
 * college_id is stripped from the payload and stamped from the tenant context.
 * The name must be unique among the college's active authors; that comparison
 * is case-insensitive and whitespace-insensitive, so it lives in AuthorService
 * (which owns the normalisation) rather than in a database `unique` rule.
 */
class StoreAuthorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Author::class) ?? false;
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
            'status' => ['required', Rule::in(Author::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
