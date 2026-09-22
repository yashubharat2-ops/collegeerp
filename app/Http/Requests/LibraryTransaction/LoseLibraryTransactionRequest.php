<?php

namespace App\Http\Requests\LibraryTransaction;

use App\Models\LibraryTransaction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mark an issued loan lost. The actor and the copy status change are
 * server-controlled.
 */
class LoseLibraryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = LibraryTransaction::query()->find($this->route('library_transaction'));

        if (! $transaction) {
            abort(404);
        }

        return $this->user()?->can('markLost', $transaction) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'returned_by', 'issued_by', 'status', 'book_copy_id', 'library_member_id', 'returned_on'] as $field) {
            $this->request->remove($field);
        }

        if ($this->has('remarks') && $this->input('remarks') === '') {
            $this->merge(['remarks' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
