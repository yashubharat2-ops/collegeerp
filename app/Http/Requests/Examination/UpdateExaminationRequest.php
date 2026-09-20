<?php

namespace App\Http\Requests\Examination;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateExaminationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = Examination::query()->find((int) $this->route('examination'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->route('examination');
        $academicYearId = (int) $this->input('academic_year_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('examinations', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_term_id' => [
                'required',
                'integer',
                Rule::exists('academic_terms', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->whereNull('deleted_at'),
            ],
            'exam_type' => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(Examination::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $collegeId = app(TenantContext::class)->id();
            $yearId = $this->input('academic_year_id');
            $termId = $this->input('academic_term_id');

            if ($yearId && $termId) {
                $valid = AcademicTerm::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $yearId)
                    ->where('id', $termId)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $valid) {
                    $v->errors()->add('academic_term_id', 'The academic term must belong to the selected academic year.');
                    $v->errors()->add('academic_year_id', 'The academic term must belong to the selected academic year.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
