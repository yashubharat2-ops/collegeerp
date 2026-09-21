<?php

namespace App\Http\Requests\ResultCalculation;

use App\Models\ExamResult;
use App\Models\Examination;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate one (re)calculation run.
 *
 * Every referenced record is checked against the ACTIVE college: an examination,
 * grade scale, program, section or enrollment belonging to another tenant can
 * never be selected here.
 */
class CalculateResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $this->boolean('recalculate', $this->isRecalculateRoute())
            ? $user->can('recalculate', ExamResult::class)
            : $user->can('calculate', ExamResult::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'calculated_by', 'calculated_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'examination_id' => [
                'required',
                'integer',
                Rule::exists('examinations', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            // The configured Grade Scale must be selected/associated before
            // calculation, and must belong to the same college.
            'grade_scale_id' => [
                'nullable',
                'integer',
                Rule::exists('grade_scales', 'id')
                    ->where('college_id', $collegeId)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'program_id' => [
                'nullable',
                'integer',
                Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'section_id' => [
                'nullable',
                'integer',
                Rule::exists('sections', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'student_enrollment_id' => [
                'nullable',
                'integer',
                Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'recalculate' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'grade_scale_id.exists' => 'The selected grade scale is invalid or not active for this college.',
        ];
    }

    public function scope(): array
    {
        return [
            'program_id' => $this->input('program_id'),
            'section_id' => $this->input('section_id'),
            'student_enrollment_id' => $this->input('student_enrollment_id'),
        ];
    }

    private function isRecalculateRoute(): bool
    {
        return $this->routeIs('*.recalculate');
    }

    public function examination(): Examination
    {
        return Examination::query()->findOrFail((int) $this->input('examination_id'));
    }
}
