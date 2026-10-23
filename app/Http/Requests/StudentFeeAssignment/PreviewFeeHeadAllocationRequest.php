<?php

namespace App\Http\Requests\StudentFeeAssignment;

use App\Domain\Finance\Services\FeeHeadAllocationService;
use App\Models\StudentFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validate allocation configuration; canonical filters are normalized by StudentFilter. */
class PreviewFeeHeadAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentFeeAssignment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Amounts, tenant, batch ids and transaction rows are server-owned.
        foreach (['college_id', 'assigned_amount', 'batch_id', 'allocation_batch_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
        if ($this->input('selection_mode') === 'all_filtered') {
            $this->merge(['student_enrollment_ids' => []]);
        }
    }

    public function rules(): array
    {
        return [
            'fee_structure_id' => ['required', 'integer', 'min:1'],
            'fee_head_ids' => ['required', 'array', 'min:1', 'max:'.FeeHeadAllocationService::MAX_HEADS],
            'fee_head_ids.*' => ['integer', 'min:1', 'distinct'],
            'selection_mode' => ['required', Rule::in(['selected', 'all_filtered'])],
            'student_enrollment_ids' => ['required_if:selection_mode,selected', 'array', 'max:'.FeeHeadAllocationService::MAX_EXPLICIT_IDS],
            'student_enrollment_ids.*' => ['integer', 'min:1', 'distinct'],
            'assigned_at' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(StudentFeeAssignment::PAYABLE_STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'fee_head_ids' => 'Fee Heads',
            'student_enrollment_ids' => 'selected enrollments',
            'assigned_at' => 'assignment date',
        ];
    }
}
