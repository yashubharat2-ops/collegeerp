<?php

namespace App\Http\Requests\LeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

class DecisionLeaveRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['approval_remarks' => ['nullable', 'string', 'max:2000']]; }
}
