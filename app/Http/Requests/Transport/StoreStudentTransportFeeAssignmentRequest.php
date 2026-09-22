<?php

namespace App\Http\Requests\Transport;

use App\Models\StudentTransportFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Student transport fee assignment creation.
 *
 * The amount is NEVER input: it is snapshotted server-side from the transport
 * fee structure. The academic year is likewise stamped from the transport
 * assignment. Tenant and actor fields are stripped.
 */
class StoreStudentTransportFeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentTransportFeeAssignment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'academic_year_id', 'amount'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        return [
            'student_transport_assignment_id' => ['required', 'integer', Rule::exists('student_transport_assignments', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'transport_fee_structure_id' => ['required', 'integer', Rule::exists('transport_fee_structures', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::in(StudentTransportFeeAssignment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_until.after_or_equal' => 'The effective-until date must not be before the effective-from date.',
        ];
    }
}
