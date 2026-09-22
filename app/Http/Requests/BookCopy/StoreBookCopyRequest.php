<?php

namespace App\Http\Requests\BookCopy;

use App\Models\BookCopy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a physical copy of a book that belongs to the active college.
 *
 * college_id, created_by and updated_by are stripped. Accession number and
 * barcode are normalized before the uniqueness check so "acc-1" and "ACC-1"
 * are the same identifier. Status `issued` is not a creatable value.
 */
class StoreBookCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BookCopy::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
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

        return [
            'book_id' => [
                'required',
                'integer',
                Rule::exists('books', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'accession_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('book_copies', 'accession_number')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('book_copies', 'barcode')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'copy_number' => [
                'required',
                'integer',
                'min:1',
                'max:100000',
                Rule::unique('book_copies', 'copy_number')
                    ->where('book_id', $this->input('book_id'))
                    ->whereNull('deleted_at'),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'condition' => ['required', Rule::in(BookCopy::CONDITIONS)],
            'status' => ['required', Rule::in(BookCopy::MANUAL_STATUSES)],
            'acquired_on' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'book_id' => 'book',
            'accession_number' => 'accession number',
            'copy_number' => 'copy number',
            'acquired_on' => 'acquisition date',
        ];
    }

    public function messages(): array
    {
        return [
            'book_id.exists' => 'The selected book does not belong to the active college.',
            'accession_number.unique' => 'A copy with this accession number already exists for the active college.',
            'barcode.unique' => 'A copy with this barcode already exists for the active college.',
            'copy_number.unique' => 'This book already has an active copy with that copy number.',
            'status.in' => 'A copy becomes issued only when it is lent to a member.',
        ];
    }
}
