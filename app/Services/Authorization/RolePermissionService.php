<?php
namespace App\Services\Authorization;
use App\Models\{Role, User};
class RolePermissionService
{
    public function assign(User $user, Role $role, ?int $collegeId = null): void
    {
        abort_unless($role->college_id === null || $role->college_id === $collegeId, 403);
        if ($collegeId !== null) abort_unless($user->colleges()->whereKey($collegeId)->exists(), 403);
        $user->roles()->syncWithoutDetaching([$role->id => ['college_id' => $collegeId]]);
    }
}
