<?php

namespace App\Http\Requests\StudentAcademicRecord;

use App\Models\StudentAcademicRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentAcademicRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's record 404s here, so a
        // cross-tenant id can never reach the policy check as a 403 leak.
        $model = StudentAcademicRecord::query()->find((int) $this->route('student_academic_record'));

        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * student_id is immutable: an academic record always belongs to the student
     * it was created for. The academic context may be corrected, but only to
     * values that are valid for this college AND for each other.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');
        $programId = $this->input('program_id');
        // student_id is immutable, so the linked enrollment is constrained to
        // the record's own student here as well as in the service.
        $studentId = (int) (StudentAcademicRecord::query()->find((int) $this->route('student_academic_record'))?->student_id ?? 0);

        $sectionRule = Rule::exists('sections', 'id')
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('deleted_at');

        if ($programId) {
            $sectionRule->where('program_id', (int) $programId);
        }

        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->where('college_id', $collegeId)->where('academic_year_id', $academicYearId)->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'section_id' => ['nullable', 'integer', $sectionRule],
            'enrollment_id' => ['nullable', 'integer', Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->where('student_id', $studentId)->whereNull('deleted_at')],
            'academic_status' => ['required', Rule::in(StudentAcademicRecord::ACADEMIC_STATUSES)],
            'promotion_status' => ['required', Rule::in(StudentAcademicRecord::PROMOTION_STATUSES)],
            'completion_status' => ['required', Rule::in(StudentAcademicRecord::COMPLETION_STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('student_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
