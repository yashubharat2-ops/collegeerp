<?php

namespace App\Http\Requests\StaffAttendance;

use App\Models\StaffAttendance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = StaffAttendance::query()->find((int) $this->route('staff_attendance'));
        if (! $model) abort(404);
        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        return [
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', Rule::in(StaffAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $key) { $this->request->remove($key); }
    }
}
