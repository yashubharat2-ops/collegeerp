<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }
        if (! $this->isJson() && $this->exists('permissions') && $this->input('permissions') === null) {
            $this->merge(['permissions' => []]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::notIn([Role::SUPER_ADMIN_SLUG, 'college-admin']), Rule::unique('roles', 'slug')->where('college_id', app(TenantContext::class)->id())],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['sometimes', 'array', 'max:1000'],
            'permissions.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where('is_active', true)],
            'college_id' => ['prohibited'], 'is_system' => ['prohibited'],
        ];
    }
}
