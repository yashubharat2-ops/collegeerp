<?php

namespace App\Http\Requests\BookCopy;

use App\Models\BookCopy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a physical copy. The title (book_id) is not editable: a copy is of
 * one book. Status `issued` can only be kept, never entered, from this form.
 */
class UpdateBookCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $copy = BookCopy::query()->find($this->route('book_copy'));

        if (! $copy) {
            abort(404);
        }

        return $this->user()?->can('update', $copy) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by', 'book_id'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('accession_number'))) {
            $merge['accession_number'] = strtoupper(trim($this->input('accession_number')));
        }

        if ($this->has('barcode')) {
            $barcode = is_string($this->input('barcode')) ? strtoupper(trim($this->input('barcode'))) : $this->input('barcode');
            $merge['barcode'] = $barcode === '' ? null : $barcode;
        }

        foreach (['location', 'acquired_on', 'remarks'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $copy = BookCopy::query()->find($this->route('book_copy'));
        $statuses = $copy?->status === BookCopy::STATUS_ISSUED
            ? [BookCopy::STATUS_ISSUED]
            : BookCopy::MANUAL_STATUSES;

        return [
            'accession_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('book_copies', 'accession_number')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($copy?->getKey()),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('book_copies', 'barcode')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($copy?->getKey()),
            ],
            'copy_number' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:100000',
                Rule::unique('book_copies', 'copy_number')
                    ->where('book_id', $copy?->book_id)
                    ->whereNull('deleted_at')
                    ->ignore($copy?->getKey()),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'condition' => ['sometimes', 'required', Rule::in(BookCopy::CONDITIONS)],
            'status' => ['sometimes', 'required', Rule::in($statuses)],
            'acquired_on' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'accession_number' => 'accession number',
            'copy_number' => 'copy number',
            'acquired_on' => 'acquisition date',
        ];
    }

    public function messages(): array
    {
        return [
            'accession_number.unique' => 'A copy with this accession number already exists for the active college.',
            'barcode.unique' => 'A copy with this barcode already exists for the active college.',
            'copy_number.unique' => 'This book already has an active copy with that copy number.',
            'status.in' => 'An issued copy can only be returned or marked lost through Issue / Return.',
        ];
    }
}
