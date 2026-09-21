<?php

namespace App\Http\Requests\FeeCategory;

use App\Models\FeeCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped fee category.
 *
 * The category is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdateFeeCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = FeeCategory::query()->find($this->route('fee_category'));

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
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $category = FeeCategory::query()->find($this->route('fee_category'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('fee_categories', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($category?->getKey()),
            ],
            'status' => ['sometimes', 'required', Rule::in(FeeCategory::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
