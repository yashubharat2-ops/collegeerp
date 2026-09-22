<?php

namespace App\Http\Requests\Book;

use App\Domain\Library\Rules\ValidIsbn;
use App\Domain\Library\Support\Isbn;
use App\Models\Book;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped book master.
 *
 * The book is resolved through CollegeScope, so a foreign-tenant id can never
 * be updated here; foreign keys are validated contextually exactly as on
 * create, and the uniqueness rules ignore the book's own row.
 */
class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $book = Book::query()->find($this->route('book'));

        if (! $book) {
            abort(404);
        }

        return $this->user()?->can('update', $book) ?? false;
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

        if ($this->has('isbn')) {
            $merge['isbn'] = is_string($this->input('isbn')) || $this->input('isbn') === null
                ? Isbn::normalize($this->input('isbn'))
                : $this->input('isbn');
        }

        foreach (['publisher_id', 'publication_year', 'edition', 'language'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        // The edit form always posts the whole book; a multi-select with nothing
        // chosen sends no `author_ids` key at all, which means "no authors" —
        // not "leave the authors alone".
        if (! $this->has('author_ids')) {
            $merge['author_ids'] = [];
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $book = Book::query()->find($this->route('book'));

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('books', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($book?->getKey()),
            ],
            'isbn' => [
                'nullable',
                'string',
                'max:20',
                new ValidIsbn(),
                Rule::unique('books', 'isbn')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($book?->getKey()),
            ],
            'book_category_id' => [
                'sometimes',
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
            'status' => ['sometimes', 'required', Rule::in(Book::STATUSES)],
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
