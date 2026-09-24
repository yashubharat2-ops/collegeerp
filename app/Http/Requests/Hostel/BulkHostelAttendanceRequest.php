<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelAttendance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk hostel roll-call for one date.
 *
 * Rows without a status are ignored by the service. Validation is all-or-nothing:
 * a foreign allocation or an illegal status rejects the batch before any write.
 * college_id and audit fields are stripped from the payload and from every row.
 */
class BulkHostelAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelAttendance::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'marked_by', 'marked_at', 'student_enrollment_id'] as $field) {
            $this->request->remove($field);
        }

        $records = $this->input('records');
        if (! is_array($records)) {
            return;
        }

        foreach ($records as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            unset(
                $row['college_id'],
                $row['student_enrollment_id'],
                $row['created_by'],
                $row['updated_by'],
                $row['marked_by'],
                $row['marked_at'],
            );

            if (($row['attendance_status'] ?? null) === '') {
                $row['attendance_status'] = null;
            }
            if (trim((string) ($row['remarks'] ?? '')) === '') {
                $row['remarks'] = null;
            }

            $records[$index] = $row;
        }

        $this->merge(['records' => $records]);
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.hostel_allocation_id' => [
                'required',
                'integer',
                Rule::exists('hostel_allocations', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'records.*.attendance_status' => ['nullable', Rule::in(HostelAttendance::STATUSES)],
            'records.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
