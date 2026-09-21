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
 * Update a tenant-scoped fee structure and (optionally) its fee heads.
 *
 * The structure itself is resolved through CollegeScope, so a foreign-tenant id
 * can never be updated here: the request authorises against the scoped model and
 * aborts with 404 when it is not visible in the active college.
 */
class UpdateFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $structure = FeeStructure::query()->find($this->route('fee_structure'));

        if (! $structure) {
            abort(404);
        }

        return $this->user()?->can('update', $structure) ?? false;
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
        $structure = FeeStructure::query()->find($this->route('fee_structure'));

        // Fields that are not resubmitted keep their persisted value, so the
        // contextual rules below validate against the effective values.
        $academicYearId = $this->filled('academic_year_id')
            ? (int) $this->input('academic_year_id')
            : (int) $structure?->academic_year_id;
        $programId = $this->filled('program_id')
            ? (int) $this->input('program_id')
            : (int) $structure?->program_id;

        return [
            'academic_year_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'program_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('programs', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_term_id' => [
                'nullable',
                'integer',
                // Contextual FK: same college AND the effective academic year.
                Rule::exists('academic_terms', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('fee_structures', 'code')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('program_id', $programId)
                    ->whereNull('deleted_at')
                    ->ignore($structure?->getKey()),
            ],
            'status' => ['sometimes', 'required', Rule::in(FeeStructure::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => [
                'nullable',
                'integer',
                // A fee head may only ever be updated inside its own structure.
                Rule::exists('fee_structure_items', 'id')
                    ->where('fee_structure_id', $structure?->getKey()),
            ],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.amount' => ['required_with:items', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.status' => ['nullable', Rule::in(FeeStructureItem::STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
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
            if ($v->errors()->isNotEmpty() || ! $this->has('items')) {
                return;
            }

            app(FeeStructureService::class)->validateItems((array) $this->input('items', []));
        });
    }
}
