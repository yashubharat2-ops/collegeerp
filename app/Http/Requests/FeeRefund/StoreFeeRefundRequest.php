<?php

namespace App\Http\Requests\FeeRefund;

use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise a refund against an actual collection.
 *
 * The payment must belong to the active college and be completed (a
 * cancelled/reversed payment can never be refunded); the amount is bounded here
 * and capped against the payment's live refundable amount inside
 * FeeRefundService under a row lock. `refund_number`, `status` and every
 * approval/processing field are server-controlled.
 */
class StoreFeeRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeRefund::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'refund_number', 'status', 'approved_by', 'approved_at', 'processed_by', 'processed_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'fee_payment_id' => [
                'required',
                'integer',
                Rule::exists('fee_payments', 'id')
                    ->where('college_id', $collegeId)
                    ->where('status', FeePayment::STATUS_COMPLETED)
                    ->whereNull('deleted_at'),
            ],
            'refund_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['fee_payment_id' => 'payment'];
    }
}
