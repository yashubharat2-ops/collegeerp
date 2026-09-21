<?php

namespace App\Http\Requests\FeeCategory;

use App\Models\FeeCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tenant-scoped fee category.
 *
 * college_id is stripped from the payload and stamped from the tenant context;
 * the code is unique among the college's active categories.
 */
class StoreFeeCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeCategory::class) ?? false;
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('fee_categories', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'status' => ['required', Rule::in(FeeCategory::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
