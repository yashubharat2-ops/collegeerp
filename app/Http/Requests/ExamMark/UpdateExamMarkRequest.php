<?php

namespace App\Http\Requests\ExamMark;

use App\Models\ExamMark;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Corrections to an existing marks record. The record identity (exam
 * schedule + student enrollment) is fixed — allowing it to change on update
 * would open an IDOR path onto another student's record.
 *
 * Absent / withheld decision (see ExamMark model): obtained_marks is forced
 * to NULL for scoreless statuses.
 */
class UpdateExamMarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = ExamMark::query()->find((int) $this->route('exam_mark'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Identity and provenance fields are never editable through the form.
        foreach (['college_id', 'exam_schedule_id', 'student_enrollment_id', 'entered_at', 'entered_by', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (in_array($this->input('status'), ExamMark::SCORELESS_STATUSES, true)) {
            $this->merge(['obtained_marks' => null]);
        }
    }

    public function rules(): array
    {
        return [
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

            if ($this->input('status') === ExamMark::STATUS_ENTERED && blank($this->input('obtained_marks'))) {
                $v->errors()->add('obtained_marks', 'Obtained marks are required when the status is entered.');
            }
        });
    }
}
