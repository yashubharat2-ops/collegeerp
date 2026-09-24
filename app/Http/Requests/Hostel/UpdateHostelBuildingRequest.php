<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelBuilding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a building / block.
 *
 * The parent hostel id is stripped (reparenting is not exposed); the service
 * still refuses a forged one. The building is resolved through CollegeScope,
 * so a foreign-tenant id can never be updated here.
 */
class UpdateHostelBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = HostelBuilding::query()->find($this->route('building'));

        if (! $building) {
            abort(404);
        }

        return $this->user()?->can('update', $building) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'hostel_id', 'created_by', 'updated_by'] as $field) {
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
        $building = HostelBuilding::query()->find($this->route('building'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_buildings', 'code')
                    ->where('college_id', $collegeId)
                    ->where('hostel_id', $building?->hostel_id)
                    ->ignore($building?->getKey()),
            ],
            'floors' => ['nullable', 'integer', 'min:1', 'max:200'],
            'status' => ['sometimes', 'required', Rule::in(HostelBuilding::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
