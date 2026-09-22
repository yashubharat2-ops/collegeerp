<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\LeaveRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = LeaveRequest::query()->find((int) $this->route('leave_request'));
        if (! $model) abort(404);
        return $this->user()?->can('update', $model) ?? false;
    }
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        return [
            'faculty_id' => ['required', 'integer', Rule::exists('faculties', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')->where('status', 'active')],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:4000'],
        ];
    }
    protected function prepareForValidation(): void { foreach (['college_id', 'status', 'days', 'requested_by', 'approved_by', 'created_by', 'updated_by'] as $key) $this->request->remove($key); }
}
