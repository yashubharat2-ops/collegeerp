<?php

namespace App\Http\Requests\GradeScale;

use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Services\Examinations\GradeScaleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Update a tenant-scoped grade scale and (optionally) its grade bands.
 *
 * The scale itself is resolved through CollegeScope by the route binding, so a
 * foreign-tenant id can never be updated here.
 */
class UpdateGradeScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scale = GradeScale::query()->find((int) $this->route('grade_scale'));

        if (! $scale) {
            abort(404);
        }

        return $this->user()?->can('update', $scale) ?? false;
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
        $scale = GradeScale::query()->find((int) $this->route('grade_scale'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('grade_scales', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($scale?->getKey()),
            ],
            'status' => ['sometimes', 'required', Rule::in(GradeScale::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => [
                'nullable',
                'integer',
                // A grade band may only ever be updated inside its own scale.
                Rule::exists('grade_scale_items', 'id')->where('grade_scale_id', $scale?->getKey()),
            ],
            'items.*.grade' => ['required_with:items', 'string', 'max:20'],
            'items.*.min_percentage' => ['required_with:items', 'numeric', 'min:0', 'max:100'],
            'items.*.max_percentage' => ['required_with:items', 'numeric', 'min:0', 'max:100', 'gte:items.*.min_percentage'],
            'items.*.grade_point' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.status' => ['nullable', Rule::in(GradeScaleItem::STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.min_percentage' => 'minimum percentage',
            'items.*.max_percentage' => 'maximum percentage',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty() || ! $this->has('items')) {
                return;
            }

            app(GradeScaleService::class)->validateItems((array) $this->input('items', []));
        });
    }
}
