<?php

namespace App\Http\Requests\FeePayment;

use App\Models\FeePayment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correct the descriptive fields of a recorded collection.
 *
 * `amount`, `student_fee_assignment_id` and `status` are not accepted here: money
 * that was received is never rewritten. Reversal is a dedicated, audited action
 * (FeeCollectionController::cancel) and a wrong amount is corrected by cancelling
 * the payment and collecting the right one.
 */
class UpdateFeePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = FeePayment::query()->find($this->route('fee_collection'));

        if (! $payment) {
            abort(404);
        }

        return $this->user()?->can('update', $payment) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'amount', 'payment_number', 'student_fee_assignment_id', 'status', 'collected_by', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['sometimes', 'required', 'date'],
            'payment_mode' => ['sometimes', 'required', 'string', 'max:30'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
