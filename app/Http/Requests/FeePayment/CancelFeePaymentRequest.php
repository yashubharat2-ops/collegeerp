<?php

namespace App\Http\Requests\FeePayment;

use App\Models\FeePayment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reverse a recorded collection.
 *
 * The reversal is audit-logged with the actor, the time and the reason; the row
 * itself is kept and flagged, never deleted or zeroed.
 */
class CancelFeePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = FeePayment::query()->find($this->route('fee_collection'));

        if (! $payment) {
            abort(404);
        }

        return $this->user()?->can('cancel', $payment) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'amount', 'payment_number', 'status', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
