<?php

namespace App\Http\Requests\LibraryMember;

use App\Models\LibraryMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a library membership. The enrollment is frozen: this is not a way to
 * re-point a membership at a different student.
 */
class UpdateLibraryMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = LibraryMember::query()->find($this->route('library_member'));

        if (! $member) {
            abort(404);
        }

        return $this->user()?->can('update', $member) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'student_enrollment_id'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('member_code'))) {
            $merge['member_code'] = strtoupper(trim($this->input('member_code')));
        }

        if ($this->has('expiry_date') && $this->input('expiry_date') === '') {
            $merge['expiry_date'] = null;
        }

        if ($this->has('remarks') && $this->input('remarks') === '') {
            $merge['remarks'] = null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $member = LibraryMember::query()->find($this->route('library_member'));

        return [
            'member_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('library_members', 'member_code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($member?->getKey()),
            ],
            'membership_date' => ['sometimes', 'required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:membership_date'],
            'status' => ['sometimes', 'required', Rule::in(LibraryMember::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'member_code' => 'member code',
            'membership_date' => 'membership date',
            'expiry_date' => 'expiry date',
        ];
    }

    public function messages(): array
    {
        return [
            'member_code.unique' => 'A library member with this code already exists for the active college.',
        ];
    }
}
