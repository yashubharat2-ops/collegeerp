<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelAllocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Vacate an active hostel allocation.
 */
class VacateHostelAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $allocation = $this->route('allocation');

        if (is_string($allocation)) {
            $allocation = HostelAllocation::query()->find($allocation);
        }

        if (! $allocation) {
            return false;
        }

        return $this->user()?->can('vacate', $allocation) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('remarks'))) {
            $this->merge([
                'remarks' => trim($this->input('remarks')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'vacated_date' => [
                'required',
                'date',
                'after_or_equal:allocation_date',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'vacated_date.after_or_equal' =>
                'The vacated date must not be earlier than the allocation date.',
        ];
    }
}
