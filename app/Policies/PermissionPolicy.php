<?php
namespace App\Policies;
use App\Models\{Permission, User};
class PermissionPolicy
{
    public function viewAny(User $user): bool { return $user->isSuperAdmin() || $user->hasPermission('permissions.view'); }
}
