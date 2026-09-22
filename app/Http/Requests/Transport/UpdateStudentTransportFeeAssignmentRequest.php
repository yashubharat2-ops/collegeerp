<?php

namespace App\Http\Requests\Transport;

use App\Models\StudentTransportFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Student transport fee assignment update (bookkeeping fields only).
 *
 * The transport assignment, the fee structure and the snapshotted amount are
 * immutable financial history and are NOT accepted here.
 */
class UpdateStudentTransportFeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's fee assignment 404s here.
        $record = StudentTransportFeeAssignment::query()->find((int) $this->route('transport_fee'));

        if (! $record) {
            abort(404);
        }

        return $this->user()?->can('update', $record) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'academic_year_id', 'amount',
                     'student_transport_assignment_id', 'transport_fee_structure_id'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
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
