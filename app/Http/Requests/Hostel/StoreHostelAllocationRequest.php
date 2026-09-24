<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelAllocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a hostel allocation: existing enrollment to existing bed.
 *
 * college_id and actor fields are stamped server-side. Hierarchy validation
 * (building→hostel, room→building, bed→room) is enforced in the service under
 * row locks; the request only checks contextual existence in the active college.
 */
class StoreHostelAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelAllocation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('remarks'))) {
            $this->merge(['remarks' => trim($this->input('remarks'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'student_enrollment_id' => [
                'required',
                'integer',
                Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'hostel_id' => [
                'required',
                'integer',
                Rule::exists('hostels', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'hostel_building_id' => [
                'required',
                'integer',
                Rule::exists('hostel_buildings', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'hostel_room_id' => [
                'required',
                'integer',
                Rule::exists('hostel_rooms', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'hostel_bed_id' => [
                'required',
                'integer',
                Rule::exists('hostel_beds', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'allocation_date' => ['required', 'date', 'before_or_equal:today'],
            'vacated_date' => ['nullable', 'date', 'after_or_equal:allocation_date'],
            'status' => ['sometimes', Rule::in(HostelAllocation::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
