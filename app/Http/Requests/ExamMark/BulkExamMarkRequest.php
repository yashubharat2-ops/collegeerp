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
 * Bulk marks save for one exam schedule. A row is only processed when it
 * carries obtained marks and/or an explicit status, so untouched rows on the
 * entry grid are left exactly as they are. Validation is all-or-nothing: if
 * any submitted row is invalid the whole batch is rejected before anything
 * is written, and every error is reported against the offending row/field.
 */
class BulkExamMarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExamMark::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');

        // The entry grid sends every visible row; untouched inputs arrive as
        // empty strings. Normalize them to null so nullable rules and the
        // row-skipping logic treat them as "not filled".
        $records = $this->input('records');
        if (is_array($records)) {
            foreach ($records as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (['max_marks', 'passing_marks', 'obtained_marks', 'status', 'remarks'] as $field) {
                    if (trim((string) ($row[$field] ?? '')) === '') {
                        $records[$index][$field] = null;
                    }
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
            // Per-row cross-field checks (obtained <= max, passing <= max)
            // happen in withValidator because Laravel field references cannot
            // resolve sibling keys inside repeated rows.
            'records.*.max_marks' => ['nullable', 'numeric', 'gt:0', 'max:10000'],
            'records.*.passing_marks' => ['nullable', 'numeric', 'min:0'],
            'records.*.obtained_marks' => ['nullable', 'numeric', 'min:0'],
            'records.*.status' => ['nullable', Rule::in(ExamMark::STATUSES)],
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
                $status = $row['status'] ?? null;
                $status = ($status === '' ? null : $status);
                $obtained = $row['obtained_marks'] ?? null;
                $obtained = ($obtained === '' ? null : $obtained);

                // Untouched grid rows (no marks typed, no status chosen) are
                // skipped server-side, mirroring the marks entry UI.
                if ($status === null && $obtained === null) {
                    continue;
                }

                if (isset($seen[$enrollmentId])) {
                    $v->errors()->add("records.{$index}.student_enrollment_id", 'The same student enrollment was submitted more than once in this batch.');
                    continue;
                }
                $seen[$enrollmentId] = true;

                if (! $eligibility->isEligible($schedule, $enrollmentId)) {
                    $v->errors()->add("records.{$index}.student_enrollment_id", 'The selected student enrollment is not eligible for this exam schedule.');
                    continue;
                }

                $maxMarks = $row['max_marks'] ?? null;
                $maxMarks = ($maxMarks === '' ? null : $maxMarks);
                $passingMarks = $row['passing_marks'] ?? null;
                $passingMarks = ($passingMarks === '' ? null : $passingMarks);

                if ($maxMarks === null || ! is_numeric($maxMarks) || (float) $maxMarks <= 0) {
                    $v->errors()->add("records.{$index}.max_marks", 'Max marks must be a positive number.');
                    continue;
                }
                if ($passingMarks === null || ! is_numeric($passingMarks)) {
                    $v->errors()->add("records.{$index}.passing_marks", 'Passing marks are required.');
                    continue;
                }
                if ((float) $passingMarks > (float) $maxMarks) {
                    $v->errors()->add("records.{$index}.passing_marks", 'Passing marks cannot exceed max marks.');
                }

                $effectiveStatus = $status ?? ExamMark::STATUS_ENTERED;

                if (in_array($effectiveStatus, ExamMark::SCORELESS_STATUSES, true)) {
                    // Obtained marks are ignored (forced NULL) for absent /
                    // withheld rows — see ExamMark model.
                    continue;
                }

                if ($obtained === null) {
                    if ($status === ExamMark::STATUS_ENTERED) {
                        $v->errors()->add("records.{$index}.obtained_marks", 'Obtained marks are required when the status is entered.');
                    }
                    continue;
                }

                if ((float) $obtained > (float) $maxMarks) {
                    $v->errors()->add("records.{$index}.obtained_marks", 'Obtained marks cannot exceed max marks.');
                }
            }
        });
    }
}
