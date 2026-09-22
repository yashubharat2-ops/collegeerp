<?php

namespace App\Http\Requests\Transport;

use App\Models\StudentTransportAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Student transport assignment update. The enrollment and academic year are
 * immutable (history) — re-pointing is done by cancelling and creating a new
 * assignment — so they are not accepted here.
 */
class UpdateStudentTransportAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's assignment 404s here.
        $record = StudentTransportAssignment::query()->find((int) $this->route('transport_assignment'));

        if (! $record) {
            abort(404);
        }

        return $this->user()?->can('update', $record) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'student_enrollment_id', 'academic_year_id'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        return [
            'transport_route_id' => ['required', 'integer', Rule::exists('transport_routes', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
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
