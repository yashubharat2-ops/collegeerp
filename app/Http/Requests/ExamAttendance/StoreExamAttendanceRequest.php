<?php

namespace App\Http\Requests\ExamAttendance;

use App\Models\ExamAttendance;
use App\Models\ExamSchedule;
use App\Services\Examinations\ExamEligibilityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Single exam attendance marking. All references are validated against the
 * ACTIVE tenant only, and the enrollment must be academically eligible for
 * the schedule (see ExamEligibilityService). college_id is never accepted
 * from request data.
 */
class StoreExamAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExamAttendance::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('marked_at');
        $this->request->remove('marked_by');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
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
            'attendance_status' => ['required', Rule::in(ExamAttendance::STATUSES)],
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

            // Duplicate guard (application level; a partial unique index also
            // protects active rows on SQLite/Postgres).
            $duplicate = ExamAttendance::query()
                ->where('exam_schedule_id', $schedule->id)
                ->where('student_enrollment_id', $enrollmentId)
                ->exists();

            if ($duplicate) {
                $v->errors()->add('student_enrollment_id', 'Attendance for this student enrollment and exam schedule already exists. Update the existing record instead.');
            }
        });
    }
}
