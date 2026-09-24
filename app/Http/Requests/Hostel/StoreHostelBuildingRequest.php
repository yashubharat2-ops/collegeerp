<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelBuilding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a building / block under an existing hostel of the active college.
 *
 * The parent hostel id is validated contextually against the active college
 * (including soft-deleted rows being excluded); college_id and the actor
 * fields are stamped server-side.
 */
class StoreHostelBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelBuilding::class) ?? false;
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
            'hostel_id' => [
                'required',
                'integer',
                Rule::exists('hostels', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_buildings', 'code')
                    ->where('college_id', $collegeId)
                    ->where('hostel_id', $this->input('hostel_id')),
            ],
            'floors' => ['nullable', 'integer', 'min:1', 'max:200'],
            'status' => ['required', Rule::in(HostelBuilding::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
