<?php

namespace App\Http\Requests\LibraryTransaction;

use App\Models\LibraryTransaction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correct the remarks on a circulation record. Parties, dates and status are
 * not editable here — return, lost and renewal are separate actions.
 */
class UpdateLibraryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = LibraryTransaction::query()->find($this->route('library_transaction'));

        if (! $transaction) {
            abort(404);
        }

        return $this->user()?->can('update', $transaction) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'college_id', 'created_by', 'updated_by', 'issued_by', 'returned_by',
            'book_copy_id', 'library_member_id', 'issued_on', 'due_on', 'returned_on', 'status',
        ] as $field) {
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
