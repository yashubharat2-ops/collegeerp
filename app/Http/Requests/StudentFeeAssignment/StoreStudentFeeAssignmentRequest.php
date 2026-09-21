<?php

namespace App\Http\Requests\StudentFeeAssignment;

use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a student fee assignment.
 *
 * Both foreign keys are validated CONTEXTUALLY against the active college: the
 * enrollment must belong to it and must not be cancelled/withdrawn, the fee
 * structure must belong to it. The year/program match against the enrollment is
 * re-checked by StudentFeeAssignmentService inside the transaction, and the
 * assigned amount is computed there — the browser cannot send one.
 */
class StoreStudentFeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentFeeAssignment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'assigned_amount', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'student_enrollment_id' => [
                'required',
                'integer',
                Rule::exists('student_enrollments', 'id')
                    ->where('college_id', $collegeId)
                    ->whereIn('status', ['active', 'completed'])
                    ->whereNull('deleted_at'),
            ],
            'fee_structure_id' => [
                'required',
                'integer',
                Rule::exists('fee_structures', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'assigned_at' => ['required', 'date'],
            'status' => ['required', Rule::in(StudentFeeAssignment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'student_enrollment_id' => 'student enrollment',
            'fee_structure_id' => 'fee structure',
            'assigned_at' => 'assignment date',
        ];
    }
}
