<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelRoom;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a room under an existing building / block of the active college.
 *
 * `hostel_id` is server-derived from the building and never accepted from
 * the payload; college_id and the actor fields are stamped server-side.
 */
class StoreHostelRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelRoom::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'hostel_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('room_number'))) {
            $this->merge(['room_number' => trim($this->input('room_number'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'building_id' => [
                'required',
                'integer',
                Rule::exists('hostel_buildings', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'room_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_rooms', 'room_number')
                    ->where('college_id', $collegeId)
                    ->where('building_id', $this->input('building_id')),
            ],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200'],
            'room_type' => ['nullable', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'status' => ['required', Rule::in(HostelRoom::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
