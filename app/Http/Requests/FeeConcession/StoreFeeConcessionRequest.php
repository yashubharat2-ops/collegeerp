<?php

namespace App\Http\Requests\FeeConcession;

use App\Models\FeeConcession;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise a concession on a fee assignment.
 *
 * Only `type` and `value` are accepted as the concession's input. The money
 * amount, the approval metadata and the status transitions to `approved` are
 * computed/set server-side; the cap against the applicable fee is enforced by
 * FeeConcessionService under an assignment row lock.
 */
class StoreFeeConcessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeConcession::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'amount', 'approved_by', 'approved_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        // An approval is never set through a create/update payload.
        if ($this->input('status') === FeeConcession::STATUS_APPROVED) {
            $this->merge(['status' => FeeConcession::STATUS_PENDING]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'student_fee_assignment_id' => [
                'required',
                'integer',
                Rule::exists('student_fee_assignments', 'id')
                    ->where('college_id', $collegeId)
                    ->whereIn('status', StudentFeeAssignment::PAYABLE_STATUSES)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::in(FeeConcession::TYPES)],
            // A percentage is bounded 0–100; a fixed amount is non-negative
            // money. The exact bound depends on the type, so it is enforced in
            // the service (which owns the assignment snapshot).
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([
                FeeConcession::STATUS_PENDING,
                FeeConcession::STATUS_REJECTED,
                FeeConcession::STATUS_CANCELLED,
            ])],
        ];
    }

    public function attributes(): array
    {
        return ['student_fee_assignment_id' => 'fee assignment'];
    }
}
