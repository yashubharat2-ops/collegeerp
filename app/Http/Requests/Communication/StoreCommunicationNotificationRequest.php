<?php

namespace App\Http\Requests\Communication;

use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Models\CommunicationNotification;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Send an internal notification.
 *
 * The recipient may be posted either as `recipient_type` + `recipient_id` or
 * as the form's combined `recipient` value ("student:42"), which removes any
 * ambiguity between ids of different masters. The recipient must be an
 * existing user (active member) / student / staff member of the ACTIVE
 * college. college_id, created_by and read_at are never input.
 */
class StoreCommunicationNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CommunicationNotification::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'read_at'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];
        $combined = $this->input('recipient');

        if (is_string($combined) && preg_match('/^([a-z_]+):(\d+)$/', $combined, $matches)) {
            $merge['recipient_type'] = $matches[1];
            $merge['recipient_id'] = $matches[2];
        }

        if (is_string($this->input('title'))) {
            $merge['title'] = trim($this->input('title'));
        }

        if ($this->has('notification_type')) {
            $merge['notification_type'] = CommunicationTypes::normalize($this->input('notification_type'));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $collegeId = (int) app(TenantContext::class)->id();

        return [
            'recipient_type' => ['required', 'string', Rule::in(array_keys(NotificationRecipients::TYPES))],
            'recipient_id' => [
                'bail',
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($collegeId): void {
                    $type = $this->input('recipient_type');

                    if (! NotificationRecipients::isValidType($type)) {
                        return; // reported on recipient_type
                    }

                    if (! NotificationRecipients::exists($type, (int) $value, $collegeId)) {
                        $fail('The selected recipient does not belong to the active college.');
                    }
                },
            ],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'notification_type' => ['required', 'string', 'max:'.CommunicationTypes::MAX_LENGTH, 'regex:'.CommunicationTypes::PATTERN],
            'priority' => ['required', Rule::in(CommunicationNotification::PRIORITIES)],
        ];
    }

    public function messages(): array
    {
        return [
            'notification_type.regex' => 'The notification type may only contain letters, numbers and separators.',
        ];
    }

    public function attributes(): array
    {
        return [
            'recipient_type' => 'recipient type',
            'recipient_id' => 'recipient',
            'notification_type' => 'notification type',
        ];
    }
}
