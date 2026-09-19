<?php

namespace App\Http\Requests\StudentAcademicRecord;

use App\Models\StudentAcademicRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentAcademicRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentAcademicRecord::class) ?? false;
    }

    /**
     * college_id is the server-side tenant and is never taken from the browser.
     *
     * Every foreign key is validated tenant-aware
     * (Rule::exists(...)->where('college_id', ...)) AND contextually:
     * - the academic term must belong to the selected academic year,
     * - the section must belong to the selected academic year and program,
     * - the enrollment must belong to the selected student.
     *
     * So another college's records — or this college's records from a different
     * year/program/student — can never be attached.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');
        $studentId = (int) $this->input('student_id');
        $programId = $this->input('program_id');

        $sectionRule = Rule::exists('sections', 'id')
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('deleted_at');

        if ($programId) {
            $sectionRule->where('program_id', (int) $programId);
        }

        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'enrollment_id' => ['nullable', 'integer', Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->where('student_id', $studentId)->whereNull('deleted_at')],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->where('college_id', $collegeId)->where('academic_year_id', $academicYearId)->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'section_id' => ['nullable', 'integer', $sectionRule],
            'academic_status' => ['required', Rule::in(StudentAcademicRecord::ACADEMIC_STATUSES)],
            'promotion_status' => ['required', Rule::in(StudentAcademicRecord::PROMOTION_STATUSES)],
            'completion_status' => ['required', Rule::in(StudentAcademicRecord::COMPLETION_STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
