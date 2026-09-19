<?php

namespace App\Http\Requests\StudentEnrollment;

use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentEnrollment::class) ?? false;
    }

    /**
     * college_id and enrollment_number are never taken from the browser: the
     * tenant is the server-side context and the number is generated
     * transactionally.
     *
     * student_id, academic_year_id and program_id are validates as tenant-aware
     * foreign keys (Rule::exists(...)->where('college_id', ...)) so another
     * college's records can never be attached.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');
        $programId = $this->input('program_id');

        // Optional Section / Batch (Platform master data — never duplicated).
        // A section is only valid when it belongs to the selected academic year
        // and, when a program is chosen, to that program.
        $sectionRule = Rule::exists('sections', 'id')
            ->where('college_id', $collegeId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('deleted_at');

        if ($programId) {
            $sectionRule->where('program_id', (int) $programId);
        }

        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->where('college_id', $collegeId)],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)],
            'section_id' => ['nullable', 'integer', $sectionRule],
            'enrollment_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(StudentEnrollment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('enrollment_number');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
