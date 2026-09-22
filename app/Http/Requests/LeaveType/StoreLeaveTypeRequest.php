<?php

namespace App\Http\Requests\LeaveType;

use App\Models\LeaveType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', LeaveType::class) ?? false; }
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('leave_types', 'code')->where('college_id', $collegeId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_days_per_year' => ['nullable', 'integer', 'min:0', 'max:366'],
            'status' => ['required', Rule::in(LeaveType::STATUSES)],
        ];
    }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        $this->request->remove('college_id');
    }
}
