<?php

namespace App\Http\Requests\Hostel;

use App\Models\Hostel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tenant-scoped hostel.
 *
 * college_id / created_by / updated_by are stripped from the payload and
 * stamped from the tenant context and the authenticated user; the code is
 * unique within the college (including archived records).
 */
class StoreHostelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Hostel::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
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
                Rule::unique('hostels', 'code')->where('college_id', $collegeId),
            ],
            'hostel_type' => ['required', Rule::in(Hostel::TYPES)],
            'gender' => ['required', Rule::in(Hostel::GENDERS)],
            'status' => ['required', Rule::in(Hostel::STATUSES)],
            'address' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
