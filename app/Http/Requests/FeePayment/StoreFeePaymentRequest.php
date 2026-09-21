<?php

namespace App\Http\Requests\FeePayment;

use App\Models\FeePayment;
use App\Models\StudentFeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a fee collection.
 *
 * The assignment is validated contextually (same college, payable status). The
 * amount is only bounded here (positive, within the column range); the real cap —
 * "never more than the outstanding balance" — is enforced inside
 * FeeCollectionService under an assignment row lock, because it depends on the
 * live ledger. Any client-supplied balance, payment number or collected_by is
 * ignored.
 */
class StoreFeePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeePayment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'payment_number', 'collected_by', 'collected_at', 'status', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
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
            'payment_date' => ['required', 'date'],
            'payment_mode' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'student_fee_assignment_id' => 'fee assignment',
            'payment_mode' => 'payment mode',
            'reference_number' => 'reference number',
        ];
    }
}
