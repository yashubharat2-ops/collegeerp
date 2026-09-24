<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelBed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a hostel bed.
 *
 * The parent room id and the derived hostel / building ids are stripped
 * (reparenting is not exposed); the service still refuses a forged one. The
 * bed is resolved through CollegeScope, so a foreign-tenant id can never be
 * updated here.
 */
class UpdateHostelBedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bed = HostelBed::query()->find($this->route('bed'));

        if (! $bed) {
            abort(404);
        }

        return $this->user()?->can('update', $bed) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'hostel_id', 'building_id', 'room_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('bed_number'))) {
            $this->merge(['bed_number' => trim($this->input('bed_number'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $bed = HostelBed::query()->find($this->route('bed'));

        return [
            'bed_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_beds', 'bed_number')
                    ->where('college_id', $collegeId)
                    ->where('room_id', $bed?->room_id)
                    ->ignore($bed?->getKey()),
            ],
            'status' => ['sometimes', 'required', Rule::in(HostelBed::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
