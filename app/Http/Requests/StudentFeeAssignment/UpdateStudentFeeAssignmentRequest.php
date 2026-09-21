<?php

namespace App\Http\Requests\StudentFeeAssignment;

use App\Models\StudentFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a student fee assignment.
 *
 * Only the assignment's own bookkeeping is editable. The fee structure and the
 * assigned amount are deliberately NOT accepted: re-pricing an existing
 * assignment would silently rewrite a student's financial history.
 */
class UpdateStudentFeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = StudentFeeAssignment::query()->find($this->route('student_fee_assignment'));

        if (! $assignment) {
            abort(404);
        }

        return $this->user()?->can('update', $assignment) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'assigned_amount', 'fee_structure_id', 'student_enrollment_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
            'assigned_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', Rule::in(StudentFeeAssignment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['assigned_at' => 'assignment date'];
    }
}
