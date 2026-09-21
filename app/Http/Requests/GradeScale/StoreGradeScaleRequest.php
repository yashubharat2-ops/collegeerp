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
 * Create a tenant-scoped grade scale.
 *
 * college_id is stripped from the payload and stamped from the tenant context;
 * grade bands are validated as a whole (duplicate grades, inverted and
 * overlapping ranges) by the shared GradeScaleService rules.
 */
class StoreGradeScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GradeScale::class) ?? false;
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
                Rule::unique('grade_scales', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'status' => ['required', Rule::in(GradeScale::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.grade' => ['required', 'string', 'max:20'],
            'items.*.min_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.max_percentage' => ['required', 'numeric', 'min:0', 'max:100', 'gte:items.*.min_percentage'],
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
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Cross-row rules (duplicate grades, overlapping ranges) — the same
            // rules the calculation engine re-checks before publishing.
            app(GradeScaleService::class)->validateItems((array) $this->input('items', []));
        });
    }
}
