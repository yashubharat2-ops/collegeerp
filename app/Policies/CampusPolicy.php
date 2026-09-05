<?php
namespace App\Policies;
use App\Models\{Campus, User};
class CampusPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('campuses.view'); }
    public function view(User $user, Campus $campus): bool { return $user->hasPermission('campuses.view', $campus->college_id); }
    public function create(User $user): bool { return $user->hasPermission('campuses.create'); }
    public function update(User $user, Campus $campus): bool { return $user->hasPermission('campuses.update', $campus->college_id); }
    public function delete(User $user, Campus $campus): bool { return $user->hasPermission('campuses.delete', $campus->college_id); }
}
