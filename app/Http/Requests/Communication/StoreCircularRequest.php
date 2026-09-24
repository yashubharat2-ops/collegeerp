<?php

namespace App\Http\Requests\Communication;

use App\Domain\Communication\Services\CommunicationAttachmentService;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Models\Circular;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Create a tenant-scoped circular.
 *
 * The circular number is normalised (trimmed, upper-cased) and must be unique
 * within the ACTIVE college, archived circulars included. college_id,
 * status, audit columns and attachment metadata are never input.
 */
class StoreCircularRequest extends FormRequest
{
    /** Letters / digits, optionally separated by "/", "-", "_", "." or single spaces. */
    public const NUMBER_PATTERN = '/^[A-Z0-9](?:[A-Z0-9\/\-_. ]*[A-Z0-9])?$/';

    protected const SERVER_FIELDS = [
        'college_id', 'status', 'created_by', 'updated_by',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Circular::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (static::SERVER_FIELDS as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('circular_number'))) {
            $merge['circular_number'] = strtoupper(trim($this->input('circular_number')));
        }

        foreach (['title', 'subject'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'circular_number' => ['required', 'string', 'max:50', 'regex:'.self::NUMBER_PATTERN, $this->uniqueNumberRule()],
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => [
                'nullable',
                'date',
                'after_or_equal:issue_date',
                Rule::when(fn () => filled($this->input('publish_at')), ['after:publish_at']),
            ],
            'target_type' => ['required', 'string', Rule::in(array_keys(CommunicationTargets::forCirculars()))],
            'attachment' => CommunicationAttachmentService::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'circular_number.unique' => 'This circular number is already used in the active college (including archived circulars).',
            'circular_number.regex' => 'The circular number may contain letters, digits and the separators / - _ . (it must start and end with a letter or digit).',
            'expires_at.after' => 'The expiry date must be after the publish date.',
            'expires_at.after_or_equal' => 'The expiry date must not be before the issue date.',
        ];
    }

    public function attributes(): array
    {
        return [
            'circular_number' => 'circular number',
            'issue_date' => 'issue date',
            'publish_at' => 'publish date',
            'expires_at' => 'expiry date',
            'target_type' => 'target audience',
        ];
    }

    /** Unique within the active college, archived (soft-deleted) rows included. */
    protected function uniqueNumberRule(): Unique
    {
        return Rule::unique('circulars', 'circular_number')->where('college_id', (int) app(TenantContext::class)->id());
    }
}
