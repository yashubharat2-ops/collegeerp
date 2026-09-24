<?php

namespace App\Http\Requests\Hostel;

use App\Models\HostelBed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a bed under an existing room of the active college.
 *
 * `hostel_id` / `building_id` are server-derived from the room and never
 * accepted from the payload; college_id and the actor fields are stamped
 * server-side. `status` is the Phase 1 operational flag on the Bed master;
 * future Hostel Allocation (Phase 2) becomes the source of truth for
 * occupancy.
 */
class StoreHostelBedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HostelBed::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'hostel_id', 'building_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        if (is_string($this->input('bed_number'))) {
            $this->merge(['bed_number' => trim($this->input('bed_number'))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'room_id' => [
                'required',
                'integer',
                Rule::exists('hostel_rooms', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'bed_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hostel_beds', 'bed_number')
                    ->where('college_id', $collegeId)
                    ->where('room_id', $this->input('room_id')),
            ],
            'status' => ['required', Rule::in(HostelBed::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
