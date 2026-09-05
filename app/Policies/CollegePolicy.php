<?php
namespace App\Policies;
use App\Models\{College, User};
class CollegePolicy
{
    public function viewAny(User $user): bool { return $user->isSuperAdmin() || $user->hasPermission('colleges.view'); }
    public function view(User $user, College $college): bool { return $user->isSuperAdmin() || ($user->colleges()->whereKey($college->id)->exists() && $user->hasPermission('colleges.view', $college->id)); }
    public function update(User $user, College $college): bool { return $user->isSuperAdmin() || ($user->colleges()->whereKey($college->id)->exists() && $user->hasPermission('colleges.update', $college->id)); }
}
