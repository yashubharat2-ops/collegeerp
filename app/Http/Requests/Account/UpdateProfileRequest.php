<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields a signed-in user may change about themselves: their name and e-mail
 * address, nothing else.
 *
 * Deliberately narrower than the administration user form. Every field that decides
 * who a user is inside the ERP — their active flag, their roles and permissions, their
 * college memberships, their password, the platform's own audit and login bookkeeping —
 * is prohibited outright, so this screen can never become a route for self-escalation
 * even if a request is hand-crafted. Those fields stay where they belong: the
 * Administration module, behind its policies.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user owns their own identity fields. There is no route
        // parameter identifying another user, so this request can only ever describe
        // the person holding the session.
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email:rfc',
                'max:254',
                Rule::unique('users', 'email')->ignore($this->user()?->getKey()),
            ],
            // Protected by the Administration module, never by this screen.
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
            'current_password' => ['prohibited'],
            'is_active' => ['prohibited'],
            'status' => ['prohibited'],
            'roles' => ['prohibited'],
            'role_ids' => ['prohibited'],
            'permissions' => ['prohibited'],
            'college_id' => ['prohibited'],
            'colleges' => ['prohibited'],
            'college_ids' => ['prohibited'],
            'is_default_college' => ['prohibited'],
            'remember_token' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'last_login_at' => ['prohibited'],
            'last_login_ip' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'This email address is unavailable.'];
    }

    public function attributes(): array
    {
        return [
            'name' => 'full name',
            'email' => 'e-mail address',
            'is_active' => 'account status',
            'roles' => 'roles',
            'college_id' => 'college',
        ];
    }
}
