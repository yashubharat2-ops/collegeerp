<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Administration\UserAdministrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRolesRequest extends FormRequest
{
    public function target(): User
    {
        return app(UserAdministrationService::class)->find($this->route('user'));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('assignRoles', $this->target()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // The empty hidden input lets an HTML form explicitly clear all roles.
        // A missing roles field is still an error, never an accidental revocation.
        if (! $this->isJson() && $this->exists('roles') && $this->input('roles') === null) {
            $this->merge(['roles' => []]);
        }
    }

    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:100'],
            'roles.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where(fn ($q) => $q->where('college_id', app(TenantContext::class)->id())->where('is_active', true)->where('slug', '!=', Role::SUPER_ADMIN_SLUG))],
            'college_id' => ['prohibited'], 'colleges' => ['prohibited'], 'password' => ['prohibited'],
        ];
    }
}
