<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Administration\UserAdministrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function target(): User
    {
        return app(UserAdministrationService::class)->find($this->route('user'));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->target()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($this->target()->getKey())],
            'is_active' => ['prohibited'], 'roles' => ['missing'],
            'college_id' => ['prohibited'], 'colleges' => ['prohibited'],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'],
            'remember_token' => ['prohibited'], 'email_verified_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'This email address is unavailable.'];
    }
}
