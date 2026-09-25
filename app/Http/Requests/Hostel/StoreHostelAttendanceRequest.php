<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelAttendance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record hostel attendance for one current resident.
 *
 * college_id, marked_by, marked_at and the audit columns are stripped —
 * the service stamps them from the tenant and the actor. student_enrollment_id,
 * when posted, must match the allocation; the stored value always comes from
 * that allocation.
 */
class StoreHostelAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelAttendance::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'marked_by', 'marked_at'] as $field) {
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
            'hostel_allocation_id' => [
                'required',
                'integer',
                Rule::exists('hostel_allocations', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'student_enrollment_id' => [
                'nullable',
                'integer',
                Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'attendance_status' => ['required', Rule::in(HostelAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
