<?php

namespace App\Http\Requests\LibraryTransaction;

use App\Models\BookCopy;
use App\Models\LibraryMember;
use App\Models\LibraryTransaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue an available copy to an active member of the active college.
 *
 * issued_by, returned_by, status and college_id are server-controlled and are
 * stripped here. The service re-checks availability under a row lock; this
 * request is the first, user-facing rejection.
 */
class StoreLibraryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LibraryTransaction::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'issued_by', 'returned_by', 'status', 'returned_on'] as $field) {
            $this->request->remove($field);
        }

        if ($this->has('remarks') && $this->input('remarks') === '') {
            $this->merge(['remarks' => null]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'book_copy_id' => [
                'required',
                'integer',
                Rule::exists('book_copies', 'id')
                    ->where('college_id', $collegeId)
                    ->where('status', BookCopy::STATUS_AVAILABLE)
                    ->whereNull('deleted_at'),
            ],
            'library_member_id' => [
                'required',
                'integer',
                Rule::exists('library_members', 'id')
                    ->where('college_id', $collegeId)
                    ->where('status', LibraryMember::STATUS_ACTIVE)
                    ->whereNull('deleted_at'),
            ],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['required', 'date', 'after:issued_on'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'book_copy_id' => 'book copy',
            'library_member_id' => 'library member',
            'issued_on' => 'issue date',
            'due_on' => 'due date',
        ];
    }

    public function messages(): array
    {
        return [
            'book_copy_id.exists' => 'The selected copy is not available to issue in the active college.',
            'library_member_id.exists' => 'The selected member is not an active member of the active college.',
            'due_on.after' => 'The due date must be later than the issue date.',
        ];
    }
}
