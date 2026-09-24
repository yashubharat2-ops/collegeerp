<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelAllocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a hostel allocation — only mutable fields.
 *
 * Enrollment, academic year, hostel, building, room, bed are immutable after
 * creation (vacate and create new instead). This request validates only the
 * fields that are allowed to change.
 */
class UpdateHostelAllocationRequest extends FormRequest
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

        return $this->user()?->can('update', $allocation) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'student_enrollment_id', 'academic_year_id', 'hostel_id', 'hostel_building_id', 'hostel_room_id', 'hostel_bed_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('remarks'))) {
            $this->merge(['remarks' => trim($this->input('remarks'))]);
        }
    }

    public function rules(): array
    {
        return [
            'allocation_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'vacated_date' => ['nullable', 'date', 'after_or_equal:allocation_date'],
            'status' => ['sometimes', Rule::in(HostelAllocation::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
