<?php

namespace App\Http\Requests\FeeStructure;

use App\Domain\Finance\Services\FeeStructureService;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a tenant-scoped fee structure.
 *
 * college_id is stripped from the payload and stamped from the tenant context.
 * Every foreign key is validated CONTEXTUALLY — the referenced academic year and
 * program must belong to the active college, and a selected term must belong to
 * both that college and the submitted academic year — so a forged cross-tenant
 * (or cross-year) id can never be persisted.
 */
class StoreFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeStructure::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        // An empty term select means "the whole academic year", not a null id.
        if ($this->has('academic_term_id') && $this->input('academic_term_id') === '') {
            $this->merge(['academic_term_id' => null]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');
        $programId = (int) $this->input('program_id');

        return [
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'program_id' => [
                'required',
                'integer',
                Rule::exists('programs', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_term_id' => [
                'nullable',
                'integer',
                // Contextual FK: same college AND same academic year.
                Rule::exists('academic_terms', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                // One active structure per college / academic year / program / code.
                Rule::unique('fee_structures', 'code')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('program_id', $programId)
                    ->whereNull('deleted_at'),
            ],
            'status' => ['required', Rule::in(FeeStructure::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            // Optional fee category classification. Contextual FK: the category
            // must belong to the active college.
            'items.*.fee_category_id' => [
                'nullable',
                'integer',
                Rule::exists('fee_categories', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.status' => ['nullable', Rule::in(FeeStructureItem::STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.fee_category_id' => 'fee category',
            'academic_year_id' => 'academic year',
            'program_id' => 'program',
            'academic_term_id' => 'academic term',
            'items.*.name' => 'fee component name',
            'items.*.amount' => 'amount',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Cross-row rules (missing names, non-numeric/negative amounts,
            // duplicate fee heads) — the same rules the service re-checks.
            app(FeeStructureService::class)->validateItems((array) $this->input('items', []));
        });
    }
}
