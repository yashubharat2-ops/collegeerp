<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelRoom;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a hostel room.
 *
 * The parent building id and the derived hostel id are stripped (reparenting
 * is not exposed); the service still refuses a forged one. The room is
 * resolved through CollegeScope, so a foreign-tenant id can never be updated
 * here.
 */
class UpdateHostelRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = HostelRoom::query()->find($this->route('room'));

        if (! $room) {
            abort(404);
        }

        return $this->user()?->can('update', $room) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'hostel_id', 'building_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('room_number'))) {
            $this->merge(['room_number' => trim($this->input('room_number'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $room = HostelRoom::query()->find($this->route('room'));

        return [
            'room_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_rooms', 'room_number')
                    ->where('college_id', $collegeId)
                    ->where('building_id', $room?->building_id)
                    ->ignore($room?->getKey()),
            ],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200'],
            'room_type' => ['nullable', 'string', 'max:100'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', 'required', Rule::in(HostelRoom::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
