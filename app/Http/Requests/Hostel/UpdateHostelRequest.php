<?php

namespace App\Http\Requests\Hostel;

use App\Models\Hostel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tenant-scoped hostel.
 *
 * The hostel is resolved through CollegeScope, so a foreign-tenant id can
 * never be updated here.
 */
class UpdateHostelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hostel = Hostel::query()->find($this->route('hostel'));

        if (! $hostel) {
            abort(404);
        }

        return $this->user()?->can('update', $hostel) ?? false;
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
        $hostel = Hostel::query()->find($this->route('hostel'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('hostels', 'code')
                    ->where('college_id', $collegeId)
                    ->ignore($hostel?->getKey()),
            ],
            'hostel_type' => ['sometimes', 'required', Rule::in(Hostel::TYPES)],
            'gender' => ['sometimes', 'required', Rule::in(Hostel::GENDERS)],
            'status' => ['sometimes', 'required', Rule::in(Hostel::STATUSES)],
            'address' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
