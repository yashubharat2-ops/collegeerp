<?php

namespace App\Http\Requests\FeeRefund;

use App\Models\FeeRefund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a refund that has not been processed yet.
 *
 * Changing the amount resets the refund to `pending` and clears the previous
 * approval; approval itself is a separate ability with its own action.
 */
class UpdateFeeRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $refund = FeeRefund::query()->find($this->route('refund'));

        if (! $refund) {
            abort(404);
        }

        return $this->user()?->can('update', $refund) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'fee_payment_id', 'refund_number', 'approved_by', 'approved_at', 'processed_by', 'processed_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (in_array($this->input('status'), [FeeRefund::STATUS_APPROVED, FeeRefund::STATUS_PROCESSED], true)) {
            $this->merge(['status' => FeeRefund::STATUS_PENDING]);
        }
    }

    public function rules(): array
    {
        return [
            'refund_date' => ['sometimes', 'required', 'date'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([
                FeeRefund::STATUS_PENDING,
                FeeRefund::STATUS_REJECTED,
                FeeRefund::STATUS_CANCELLED,
            ])],
        ];
    }
}
