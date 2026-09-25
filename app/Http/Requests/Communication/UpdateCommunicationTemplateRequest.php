<?php

namespace App\Http\Requests\Communication;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Models\CommunicationTemplate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a reusable SMS / e-mail template.
 *
 * Authorization resolves the template under the ACTIVE tenant, so another
 * college's template can never be edited. Uniqueness of `code` ignores the
 * template itself and stays scoped to the college.
 */
class UpdateCommunicationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->template();

        if (! $template) {
            abort(404); // another college's id is never disclosed
        }

        return $this->user()?->can('update', $template) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }

        $merge = [];

        if (is_string($this->input('code'))) {
            $merge['code'] = strtoupper(trim((string) $this->input('code')));
        }

        if (is_string($this->input('name'))) {
            $merge['name'] = trim((string) $this->input('name'));
        }

        if ($this->input('channel') === CommunicationChannels::SMS) {
            $merge['subject'] = null;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $collegeId = (int) app(TenantContext::class)->id();
        $templateId = (int) ($this->template()?->getKey() ?? 0);

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_\-]*$/',
                Rule::unique('communication_templates', 'code')
                    ->ignore($templateId)
                    ->where(fn ($query) => $query->where('college_id', $collegeId)),
            ],
            'channel' => ['required', Rule::in(CommunicationChannels::all())],
            'subject' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->input('channel') === CommunicationChannels::EMAIL)],
            'body' => ['required', 'string', 'max:5000'],
            'status' => ['required', Rule::in(CommunicationTemplate::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The template code may only contain letters, numbers, hyphens and underscores.',
            'code.unique' => 'This template code is already used in the active college.',
            'subject.required' => 'An email template needs a subject line.',
        ];
    }

    /** The tenant-scoped template of the route (CollegeScope applies). */
    public function template(): ?CommunicationTemplate
    {
        $id = $this->route('communication_template');

        if ($id instanceof CommunicationTemplate) {
            return $id;
        }

        return CommunicationTemplate::query()->find($id);
    }
}
