<?php

namespace App\Http\Requests\StudentPromotion;

use App\Models\StudentPromotion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentPromotion::class) ?? false;
    }

    /**
     * Every reference is validated tenant-aware AND contextually:
     * - the source enrollment must belong to the selected student,
     * - the target academic term must belong to the target academic year,
     * - the target section must belong to the target academic year and program.
     *
     * college_id, status, the generated target enrollment and the approval
     * stamps are never accepted from the browser. Nothing here encodes a
     * progression rule: the target year/program/section are free choices.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $studentId = (int) $this->input('student_id');
        $targetYearId = (int) $this->input('target_academic_year_id');
        $targetProgramId = $this->input('target_program_id');

        $sectionRule = Rule::exists('sections', 'id')
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $targetYearId)
            ->whereNull('deleted_at');

        if ($targetProgramId) {
            $sectionRule->where('program_id', (int) $targetProgramId);
        }

        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'source_enrollment_id' => ['required', 'integer', Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->where('student_id', $studentId)->whereNull('deleted_at')],
            // "Target year must differ from the source enrollment's year" is
            // enforced in PromoteStudent, where the source enrollment is
            // actually resolved (the browser never supplies the source year).
            'target_academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'target_program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'target_academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->where('college_id', $collegeId)->where('academic_year_id', $targetYearId)->whereNull('deleted_at')],
            'target_section_id' => ['nullable', 'integer', $sectionRule],
            'effective_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('status');
        $this->request->remove('source_academic_year_id');
        $this->request->remove('source_program_id');
        $this->request->remove('source_section_id');
        $this->request->remove('target_enrollment_id');
        $this->request->remove('approved_by');
        $this->request->remove('approved_at');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
