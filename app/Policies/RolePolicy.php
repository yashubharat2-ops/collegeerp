<?php
namespace App\Policies;
use App\Models\{Role, User};
class RolePolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('roles.view'); }
    public function update(User $user, Role $role): bool { return $role->college_id === null ? $user->isSuperAdmin() : $user->hasPermission('roles.update', $role->college_id); }
}
