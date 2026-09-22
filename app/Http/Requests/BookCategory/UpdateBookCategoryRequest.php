<?php

namespace App\Http\Requests\BookCategory;

use App\Models\BookCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped book category.
 *
 * The category is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdateBookCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = BookCategory::query()->find($this->route('book_category'));

        if (! $category) {
            abort(404);
        }

        return $this->user()?->can('update', $category) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $category = BookCategory::query()->find($this->route('book_category'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('book_categories', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($category?->getKey()),
            ],
            'status' => ['sometimes', 'required', Rule::in(BookCategory::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
