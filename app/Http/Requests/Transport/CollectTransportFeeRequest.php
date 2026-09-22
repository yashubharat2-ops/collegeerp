<?php

namespace App\Http\Requests\Transport;

use App\Models\FeePayment;
use App\Models\StudentTransportFeeAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transport fee collection (recorded through the EXISTING Finance payment
 * rows). Amount, payment number and the collector are all server-controlled;
 * the outstanding cap is enforced inside FeeCollectionService under a row lock.
 *
 * Collecting is an UPDATE-level action on the fee assignment (a separate
 * `transport_fees.update` would split the permission set the module was given).
 */
class CollectTransportFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's fee assignment 404s here.
        $record = StudentTransportFeeAssignment::query()->find((int) $this->route('transport_fee'));

        if (! $record) {
            abort(404);
        }

        return $this->user()?->can('collect', $record) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'payment_number', 'collected_by', 'collected_at', 'status', 'student_fee_assignment_id', 'transport_fee_assignment_id'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'payment_mode' => ['required', Rule::in(FeePayment::MODES)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.FeePayment::MAX_AMOUNT],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'submission_token' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.decimal' => 'The amount must be a money value with at most 2 decimals.',
        ];
    }
}
