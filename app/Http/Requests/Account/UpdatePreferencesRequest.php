<?php

namespace App\Http\Requests\Account;

use App\Services\Settings\UserPreferenceService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the account Preferences screen.
 *
 * The rule set is generated from UserPreferenceService::DEFINITIONS, the same allowlist
 * that decides what the screen renders and what the service is allowed to store. A key
 * that is not a supported preference therefore has no rule, is not validated and cannot
 * be persisted — the form can never invent a setting, and nothing an administrator owns
 * (institutional settings, roles, tenant links) is reachable from here.
 */
class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [];

        foreach (UserPreferenceService::DEFINITIONS as $key => $definition) {
            $field = $definition['field'];
            $rules[$field] = $definition['type'] === 'boolean'
                ? ['required', 'boolean']
                : ['nullable', 'string', 'max:255'];
        }

        // Nothing else, in the shape the panel posts: only the allowlisted fields above
        // are ever accepted.
        $rules['user_id'] = ['prohibited'];
        $rules['college_id'] = ['prohibited'];

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (UserPreferenceService::DEFINITIONS as $definition) {
            $attributes[$definition['field']] = $definition['label'];
        }

        return $attributes;
    }
}
