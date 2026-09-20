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
 * Bulk attendance marking for one exam schedule ("Present All" + individual
 * corrections). Rows are identified by student enrollment id; a row is only
 * processed when it carries an attendance status, so untouched rows are left
 * exactly as they are. Validation is all-or-nothing: if any submitted row is
 * invalid the whole batch is rejected before anything is written.
 */
class BulkExamAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExamAttendance::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');

        // The marking board sends every visible row; untouched selects arrive
        // as empty strings. Normalize them to null so nullable rules and the
        // row-skipping logic treat them as "not marked".
        $records = $this->input('records');
        if (is_array($records)) {
            foreach ($records as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['attendance_status'] ?? null) === '') {
                    $records[$index]['attendance_status'] = null;
                }
                if (trim((string) ($row['remarks'] ?? '')) === '') {
                    $records[$index]['remarks'] = null;
                }
            }
            $this->merge(['records' => $records]);
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
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_enrollment_id' => [
                'required',
                'integer',
                Rule::exists('student_enrollments', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'records.*.attendance_status' => ['nullable', Rule::in(ExamAttendance::STATUSES)],
            'records.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $schedule = ExamSchedule::query()->find($this->input('exam_schedule_id'));
            if (! $schedule) {
                $v->errors()->add('exam_schedule_id', 'The selected exam schedule is invalid.');

                return;
            }

            $eligibility = app(ExamEligibilityService::class);
            $seen = [];

            foreach ($this->input('records', []) as $index => $row) {
                $enrollmentId = (int) ($row['student_enrollment_id'] ?? 0);
                $status = $row['attendance_status'] ?? null;

                // Untouched rows (no status chosen) are skipped server-side,
                // mirroring the marking board UI.
                if ($status === null || $status === '') {
                    continue;
                }

                if (isset($seen[$enrollmentId])) {
                    $v->errors()->add("records.{$index}.student_enrollment_id", 'The same student enrollment was submitted more than once in this batch.');
                    continue;
                }
                $seen[$enrollmentId] = true;

                if (! $eligibility->isEligible($schedule, $enrollmentId)) {
                    $v->errors()->add("records.{$index}.student_enrollment_id", 'The selected student enrollment is not eligible for this exam schedule.');
                }
            }
        });
    }
}
