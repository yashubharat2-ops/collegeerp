<?php

namespace App\Http\Requests\ExamAttendance;

use App\Models\ExamAttendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corrections to an existing attendance record. The record identity
 * (exam schedule + student enrollment) is fixed — allowing it to change on
 * update would open an IDOR path onto another student's record.
 */
class UpdateExamAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = ExamAttendance::query()->find((int) $this->route('exam_attendance'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Identity and provenance fields are never editable through the form.
        foreach (['college_id', 'exam_schedule_id', 'student_enrollment_id', 'marked_at', 'marked_by', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
            'attendance_status' => ['required', Rule::in(ExamAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
