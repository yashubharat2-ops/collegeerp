<?php

namespace App\Http\Requests\FeeConcession;

use App\Models\FeeConcession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a concession.
 *
 * `approved` cannot be set here — approval is its own ability and its own action.
 * Changing `type` or `value` sends the concession back to `pending` and clears
 * the previous approval (an approval is for a specific amount).
 */
class UpdateFeeConcessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $concession = FeeConcession::query()->find($this->route('fee_concession'));

        if (! $concession) {
            abort(404);
        }

        return $this->user()?->can('update', $concession) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'student_fee_assignment_id', 'amount', 'approved_by', 'approved_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if ($this->input('status') === FeeConcession::STATUS_APPROVED) {
            $this->merge(['status' => FeeConcession::STATUS_PENDING]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(FeeConcession::TYPES)],
            'value' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([
                FeeConcession::STATUS_PENDING,
                FeeConcession::STATUS_REJECTED,
                FeeConcession::STATUS_CANCELLED,
            ])],
        ];
    }
}
