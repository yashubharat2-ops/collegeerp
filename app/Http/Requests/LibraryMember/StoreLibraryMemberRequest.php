<?php

namespace App\Http\Requests\LibraryMember;

use App\Models\LibraryMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a library membership for an existing enrollment of the active college.
 *
 * The enrollment is validated contextually (same college, not soft-deleted).
 * An enrollment may have only one active membership. college_id is stripped.
 */
class StoreLibraryMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LibraryMember::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
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

        $enrollmentRules = [
            'required',
            'integer',
            Rule::exists('student_enrollments', 'id')
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at'),
        ];

        if ($this->input('status') === LibraryMember::STATUS_ACTIVE) {
            $enrollmentRules[] = Rule::unique('library_members', 'student_enrollment_id')
                ->where('college_id', $collegeId)
                ->where('status', LibraryMember::STATUS_ACTIVE)
                ->whereNull('deleted_at');
        }

        return [
            'student_enrollment_id' => $enrollmentRules,
            'member_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('library_members', 'member_code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'membership_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:membership_date'],
            'status' => ['required', Rule::in(LibraryMember::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'student_enrollment_id' => 'student enrollment',
            'member_code' => 'member code',
            'membership_date' => 'membership date',
            'expiry_date' => 'expiry date',
        ];
    }

    public function messages(): array
    {
        return [
            'student_enrollment_id.exists' => 'The selected enrollment does not belong to the active college.',
            'student_enrollment_id.unique' => 'This enrollment already has an active library membership.',
            'member_code.unique' => 'A library member with this code already exists for the active college.',
        ];
    }
}
