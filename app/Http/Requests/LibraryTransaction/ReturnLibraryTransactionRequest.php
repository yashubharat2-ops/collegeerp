<?php

namespace App\Http\Requests\LibraryTransaction;

use App\Models\LibraryTransaction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Return an issued copy. returned_by is stamped from the authenticated user.
 */
class ReturnLibraryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = LibraryTransaction::query()->find($this->route('library_transaction'));

        if (! $transaction) {
            abort(404);
        }

        return $this->user()?->can('returnCopy', $transaction) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'returned_by', 'issued_by', 'status', 'book_copy_id', 'library_member_id'] as $field) {
            $this->request->remove($field);
        }

        if ($this->has('remarks') && $this->input('remarks') === '') {
            $this->merge(['remarks' => null]);
        }
    }

    public function rules(): array
    {
        $transaction = LibraryTransaction::query()->find($this->route('library_transaction'));
        $issuedOn = $transaction?->issued_on?->toDateString() ?? '1970-01-01';

        return [
            'returned_on' => ['required', 'date', 'after_or_equal:'.$issuedOn, 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'returned_on' => 'return date',
        ];
    }

    public function messages(): array
    {
        return [
            'returned_on.after_or_equal' => 'The return date cannot be before the issue date.',
            'returned_on.before_or_equal' => 'The return date cannot be in the future.',
        ];
    }
}
