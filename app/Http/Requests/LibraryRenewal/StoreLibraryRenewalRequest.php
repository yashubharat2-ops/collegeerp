<?php

namespace App\Http\Requests\LibraryRenewal;

use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renew an active issue. renewed_by is stamped from the authenticated user.
 * The new due date must be later than the issue's current due date; the
 * service re-checks that under a row lock so a concurrent renewal cannot
 * land on a stale due date.
 */
class StoreLibraryRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LibraryRenewal::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'renewed_by', 'old_due_date'] as $field) {
            $this->request->remove($field);
        }

        if ($this->has('remarks') && $this->input('remarks') === '') {
            $this->merge(['remarks' => null]);
        }

        if (! $this->filled('renewed_on')) {
            $this->merge(['renewed_on' => now()->toDateString()]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $transaction = LibraryTransaction::query()->find($this->input('issue_transaction_id'));
        $after = $transaction?->due_on?->toDateString();

        $dueRules = ['required', 'date'];

        if ($after) {
            $dueRules[] = 'after:'.$after;
        }

        return [
            'issue_transaction_id' => [
                'required',
                'integer',
                Rule::exists('library_transactions', 'id')
                    ->where('college_id', $collegeId)
                    ->where('status', LibraryTransaction::STATUS_ISSUED),
            ],
            'new_due_date' => $dueRules,
            'renewed_on' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'issue_transaction_id' => 'issue',
            'new_due_date' => 'new due date',
            'renewed_on' => 'renewal date',
        ];
    }

    public function messages(): array
    {
        return [
            'issue_transaction_id.exists' => 'Only an active issue of the active college can be renewed.',
            'new_due_date.after' => 'The new due date must be later than the current due date.',
        ];
    }
}
