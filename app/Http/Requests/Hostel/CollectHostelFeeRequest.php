<?php

namespace App\Http\Requests\Hostel;

use App\Models\FeePayment;
use App\Models\HostelFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectHostelFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('fee');
        if (is_string($assignment)) {
            $assignment = HostelFeeAssignment::query()->find($assignment);
        }
        if (! $assignment) {
            return false;
        }
        return $this->user()?->can('collect', $assignment) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'student_fee_assignment_id', 'transport_fee_assignment_id', 'hostel_fee_assignment_id', 'payment_number', 'collected_by', 'collected_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_mode' => ['required', Rule::in(FeePayment::MODES)],
            'amount' => ['required', 'numeric', 'gt:0', 'lte:9999999999.99'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'submission_token' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
