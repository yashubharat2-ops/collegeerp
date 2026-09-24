<?php

namespace App\Http\Requests\Communication;

use App\Domain\Communication\Services\CommunicationAttachmentService;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Models\Notice;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tenant-scoped notice / announcement.
 *
 * college_id, slug, status, created_by / updated_by and attachment metadata
 * are NEVER input: they are stripped here and stamped server-side
 * (NoticeService). Status only changes through the publish workflow.
 * Entity targets must exist in the ACTIVE college.
 */
class StoreNoticeRequest extends FormRequest
{
    /** Server-controlled fields that are always discarded from the payload. */
    protected const SERVER_FIELDS = [
        'college_id', 'slug', 'status', 'created_by', 'updated_by',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Notice::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (static::SERVER_FIELDS as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('title'))) {
            $merge['title'] = trim($this->input('title'));
        }

        if ($this->has('notice_type')) {
            $merge['notice_type'] = CommunicationTypes::normalize($this->input('notice_type'));
        }

        // Audience-wide targets carry no record: drop any stray target_id.
        if (! CommunicationTargets::requiresEntity($this->input('target_type'))) {
            $merge['target_id'] = null;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $collegeId = (int) app(TenantContext::class)->id();

        return [
            'title' => ['required', 'string', 'max:255'],
            'notice_type' => ['required', 'string', 'max:'.CommunicationTypes::MAX_LENGTH, 'regex:'.CommunicationTypes::PATTERN],
            'content' => ['required', 'string', 'max:50000'],
            'publish_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
            'priority' => ['required', Rule::in(Notice::PRIORITIES)],
            'target_type' => ['required', 'string', Rule::in(array_keys(CommunicationTargets::forNotices()))],
            'target_id' => [
                'bail',
                Rule::requiredIf(fn () => CommunicationTargets::requiresEntity($this->input('target_type'))),
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($collegeId): void {
                    $type = $this->input('target_type');

                    if ($value === null || ! CommunicationTargets::requiresEntity($type)) {
                        return;
                    }

                    if (! CommunicationTargets::findEntity((string) $type, (int) $value, $collegeId)) {
                        $fail('The selected target does not exist in the active college.');
                    }
                },
            ],
            'attachment' => CommunicationAttachmentService::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'expires_at.after' => 'The expiry date must be after the publish date.',
            'notice_type.regex' => 'The notice type may only contain letters, numbers and separators.',
            'target_id.required' => 'Choose the record this notice is targeted at.',
        ];
    }

    public function attributes(): array
    {
        return [
            'publish_at' => 'publish date',
            'expires_at' => 'expiry date',
            'target_type' => 'target audience',
            'target_id' => 'target record',
            'notice_type' => 'notice type',
        ];
    }
}
