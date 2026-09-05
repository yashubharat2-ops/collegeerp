<?php

namespace App\Support\Authorization;

use App\Models\User;

final class PermissionChecker
{
    public function allows(?User $user, string $permission, ?int $collegeId = null): bool
    {
        return $user?->is_active && $user->hasPermission($permission, $collegeId);
    }
}
