<?php

namespace App\Http\Requests\Transport;

use App\Models\StudentTransportAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Student transport assignment creation — only validated fields leave this
 * boundary. college_id / created_by / updated_by are NEVER input: the tenant is
 * the server-side context and the actor is the authenticated user (stripped
 * below so even a crafted payload cannot set them).
 *
 * Every foreign record is validated against the ACTIVE college here, and the
 * service re-checks all of it (including "stop belongs to route") under a lock.
 */
class StoreStudentTransportAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentTransportAssignment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        return [
            'student_enrollment_id' => ['required', 'integer', Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'transport_route_id' => ['required', 'integer', Rule::exists('transport_routes', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            // The stop's route is re-checked server-side (service + DB composite FK).
            'transport_stop_id' => ['required', 'integer', Rule::exists('transport_stops', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(StudentTransportAssignment::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date must not be before the start date.',
        ];
    }
}
