<?php

namespace App\Http\Requests\Admin;

use App\Models\CommunicationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateStatusRequest extends FormRequest
{
    public function template(): CommunicationTemplate
    {
        return CommunicationTemplate::query()->findOrFail($this->route('template'));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->template()) ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(CommunicationTemplate::STATUSES)], 'college_id' => ['prohibited'], 'channel' => ['prohibited'], 'body' => ['prohibited']];
    }
}
