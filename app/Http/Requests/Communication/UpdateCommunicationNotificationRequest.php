<?php

namespace App\Http\Requests\Communication;

use App\Domain\Communication\Support\CommunicationTypes;
use App\Models\CommunicationNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit the content of an internal notification.
 *
 * The recipient is immutable once a notification is sent, so recipient
 * fields are discarded together with the server-controlled columns. The
 * notification is resolved through CollegeScope (a foreign id 404s).
 */
class UpdateCommunicationNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notification = CommunicationNotification::query()->find($this->route('notification'));

        if (! $notification) {
            abort(404);
        }

        return $this->user()?->can('update', $notification) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'read_at', 'recipient', 'recipient_type', 'recipient_id'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

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
        return [
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
        return ['notification_type' => 'notification type'];
    }
}
