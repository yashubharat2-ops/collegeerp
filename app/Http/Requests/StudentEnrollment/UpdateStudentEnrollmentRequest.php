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

        return [
            'enrollment_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(StudentEnrollment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
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
