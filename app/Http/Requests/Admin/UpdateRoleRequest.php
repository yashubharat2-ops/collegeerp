<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Services\Administration\RoleAdministrationService;

class UpdateRoleRequest extends StoreRoleRequest
{
    public function target(): Role
    {
        return app(RoleAdministrationService::class)->find($this->route('role'));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->target()) ?? false;
    }

    public function rules(): array
    {
        return [...parent::rules(), 'slug' => ['prohibited']];
    }
}
