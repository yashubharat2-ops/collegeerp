<?php

namespace App\Http\Requests\Transport;

use App\Models\TransportFeeStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transport fee structure update. Editing the amount never re-prices existing
 * student fee assignments (their amount is snapshotted at assignment time).
 */
class UpdateTransportFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's structure 404s here.
        $record = TransportFeeStructure::query()->find((int) $this->route('transport_fee_structure'));

        if (! $record) {
            abort(404);
        }

        return $this->user()?->can('update', $record) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'transport_route_id' => ['nullable', 'integer', Rule::exists('transport_routes', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'transport_stop_id' => ['nullable', 'integer', Rule::exists('transport_stops', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('transport_fee_structures', 'code')->where('college_id', $collegeId)->ignore($this->route('transport_fee_structure'))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.TransportFeeStructure::MAX_AMOUNT],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::in(TransportFeeStructure::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.decimal' => 'The amount must be a money value with at most 2 decimals.',
            'effective_until.after_or_equal' => 'The effective-until date must not be before the effective-from date.',
        ];
    }
}
