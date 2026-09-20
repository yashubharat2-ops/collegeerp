<?php

namespace App\Http\Requests\ExamMark;

use App\Models\ExamMark;
use App\Models\ExamSchedule;
use App\Services\Examinations\ExamEligibilityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Single marks entry. All references are validated against the ACTIVE tenant
 * only, the enrollment must be academically eligible for the schedule, and
 * college_id is never accepted from request data.
 *
 * Absent / withheld decision (see ExamMark model): obtained_marks is forced
 * to NULL for scoreless statuses so no fabricated numeric score exists for a
 * student who was absent or whose marks are withheld.
 */
class StoreExamMarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExamMark::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'entered_at', 'entered_by', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (in_array($this->input('status'), ExamMark::SCORELESS_STATUSES, true)) {
            $this->merge(['obtained_marks' => null]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'exam_schedule_id' => [
                'required',
                'integer',
                Rule::exists('exam_schedules', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'student_enrollment_id' => [
                'required',
                'integer',
                Rule::exists('student_enrollments', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'passing_marks' => ['required', 'numeric', 'min:0', 'lte:max_marks'],
            'obtained_marks' => ['nullable', 'numeric', 'min:0', 'lte:max_marks'],
            'status' => ['required', Rule::in(ExamMark::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Both lookups run under CollegeScope, so a foreign-tenant id can
            // never resolve here.
            $schedule = ExamSchedule::query()->find($this->input('exam_schedule_id'));
            if (! $schedule) {
                $v->errors()->add('exam_schedule_id', 'The selected exam schedule is invalid.');

                return;
            }

            $enrollmentId = $this->input('student_enrollment_id');

            if (! app(ExamEligibilityService::class)->isEligible($schedule, $enrollmentId)) {
                $v->errors()->add('student_enrollment_id', 'The selected student enrollment is not eligible for this exam schedule.');

                return;
            }

            if ($this->input('status') === ExamMark::STATUS_ENTERED && blank($this->input('obtained_marks'))) {
                $v->errors()->add('obtained_marks', 'Obtained marks are required when the status is entered.');
            }

            // Duplicate guard (application level; a partial unique index also
            // protects active rows on SQLite/Postgres).
            $duplicate = ExamMark::query()
                ->where('exam_schedule_id', $schedule->id)
                ->where('student_enrollment_id', $enrollmentId)
                ->exists();

            if ($duplicate) {
                $v->errors()->add('student_enrollment_id', 'Marks for this student enrollment and exam schedule already exist. Update the existing record instead.');
            }
        });
    }
}
