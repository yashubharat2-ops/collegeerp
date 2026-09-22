<?php

namespace App\Http\Requests\Designation;

use App\Models\Designation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Designation::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('designations', 'code')->where('college_id', $collegeId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(Designation::STATUSES)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
