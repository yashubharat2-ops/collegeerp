<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => [$this->user()->hasPermission('users.assign_roles') ? 'sometimes' : 'prohibited', 'array', 'max:100'],
            'roles.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('college_id', $collegeId)->where('is_active', true)->where('slug', '!=', Role::SUPER_ADMIN_SLUG))],
            'college_id' => ['prohibited'], 'colleges' => ['prohibited'],
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'],
            'remember_token' => ['prohibited'], 'email_verified_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'This email address is unavailable.', 'roles.*.exists' => 'Choose active roles belonging to the active college.'];
    }
}
