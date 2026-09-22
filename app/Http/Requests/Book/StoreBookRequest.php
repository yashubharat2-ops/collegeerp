<?php

namespace App\Http\Requests\Book;

use App\Domain\Library\Rules\ValidIsbn;
use App\Domain\Library\Support\Isbn;
use App\Models\Book;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tenant-scoped book master.
 *
 * college_id is stripped from the payload and stamped from the tenant context.
 * Every foreign key is validated CONTEXTUALLY — the category, the publisher and
 * each credited author must belong to the active college — so a forged
 * cross-tenant id can never be persisted. `code` and (when given) `isbn` are
 * unique among the college's active books; the ISBN is compared in its
 * normalized form.
 */
class StoreBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('code'))) {
            $merge['code'] = strtoupper(trim($this->input('code')));
        }

        // Compare and store the canonical ISBN; an empty field is "no ISBN".
        if ($this->has('isbn')) {
            $merge['isbn'] = is_string($this->input('isbn')) || $this->input('isbn') === null
                ? Isbn::normalize($this->input('isbn'))
                : $this->input('isbn');
        }

        // Empty selects mean "not recorded", not a zero id / empty string.
        foreach (['publisher_id', 'publication_year', 'edition', 'language'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'title' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('books', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'isbn' => [
                'nullable',
                'string',
                'max:20',
                new ValidIsbn(),
                Rule::unique('books', 'isbn')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'book_category_id' => [
                'required',
                'integer',
                Rule::exists('book_categories', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'publisher_id' => [
                'nullable',
                'integer',
                Rule::exists('publishers', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'author_ids' => ['nullable', 'array', 'max:20'],
            'author_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('authors', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'edition' => ['nullable', 'string', 'max:50'],
            'publication_year' => ['nullable', 'integer', 'min:1000', 'max:'.((int) now()->year + 1)],
            'language' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Book::STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'book_category_id' => 'book category',
            'publisher_id' => 'publisher',
            'author_ids' => 'authors',
            'author_ids.*' => 'author',
            'isbn' => 'ISBN',
            'publication_year' => 'publication year',
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A book with this code already exists for the active college.',
            'isbn.unique' => 'A book with this ISBN already exists for the active college.',
            'book_category_id.exists' => 'The selected book category does not belong to the active college.',
            'publisher_id.exists' => 'The selected publisher does not belong to the active college.',
            'author_ids.*.exists' => 'One or more selected authors do not belong to the active college.',
        ];
    }
}
