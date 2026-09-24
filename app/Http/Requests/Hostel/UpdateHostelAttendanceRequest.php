<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelAttendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorized correction of an existing hostel attendance mark.
 *
 * The resident link (enrollment, allocation, college) cannot be changed here.
 * Those fields are stripped so a client cannot re-point a historical mark.
 */
class UpdateHostelAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendance = $this->route('hostel_attendance');
        if (is_string($attendance) || is_int($attendance)) {
            $attendance = HostelAttendance::query()->find($attendance);
        }

        if (! $attendance instanceof HostelAttendance) {
            return false;
        }

        return $this->user()?->can('update', $attendance) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'college_id',
            'student_enrollment_id',
            'hostel_allocation_id',
            'created_by',
            'updated_by',
            'marked_by',
            'marked_at',
        ] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('remarks'))) {
            $this->merge(['remarks' => trim($this->input('remarks'))]);
        }
    }

    public function rules(): array
    {
        return [
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'attendance_status' => ['required', Rule::in(HostelAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
