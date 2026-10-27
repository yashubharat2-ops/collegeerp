<?php

namespace App\Http\Requests\StudentEnrollment;

use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = StudentEnrollment::query()->find((int) $this->route('student_enrollment'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * student_id, academic_year_id, program_id are immutable after creation:
     * the enrollment's student/year/program triple composes the historical
     * record and is never re-pointable via the browser. college_id is the
     * server-side tenant and enrollment_number is server-generated.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        // A section move within the same enrollment is allowed (and audited);
        // the section must belong to this enrollment's own (immutable) academic
        // year and program, so it can never be re-pointed elsewhere.
        $enrollment = StudentEnrollment::query()->find((int) $this->route('student_enrollment'));

        $sectionRule = Rule::exists('sections', 'id')
            ->where('college_id', $collegeId)
            ->where('academic_year_id', (int) $enrollment?->academic_year_id)
            ->whereNull('deleted_at');

        if ($enrollment?->program_id) {
            $sectionRule->where('program_id', (int) $enrollment->program_id);
        }

        // The configured semester / year of study may be corrected, but only
        // within this enrollment's own academic year (which is immutable).
        $termRule = Rule::exists('academic_terms', 'id')
            ->where('college_id', $collegeId)
            ->where('academic_year_id', (int) $enrollment?->academic_year_id)
            ->whereNull('deleted_at');

        return [
            'academic_term_id' => ['nullable', 'integer', $termRule],
            'section_id' => ['nullable', 'integer', $sectionRule],
            'enrollment_date' => ['nullable', 'date'],
            // Manual statuses only — plus whatever this record already holds, so
            // editing an unrelated field never has to rewrite a workflow state.
            // `pending_approval` -> `active` is refused by StudentService as well:
            // that transition belongs to the approval decision.
            'status' => ['required', Rule::in(StudentEnrollment::formStatuses($enrollment?->status))],
            'remarks' => ['nullable', 'string', 'max:2000'],
            // Class/section roll number. Blank lets the server allocate one when
            // a section is attached (GenerateRollNumber); an explicit value is
            // checked against the same academic year + programme + section.
            'roll_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('enrollment_number');
        $this->request->remove('student_id');
        $this->request->remove('academic_year_id');
        $this->request->remove('program_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
