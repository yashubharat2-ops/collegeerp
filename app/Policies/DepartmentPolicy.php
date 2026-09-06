<?php
namespace App\Policies;
use App\Models\{Department, User};
class DepartmentPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('departments.view'); }
    public function view(User $user, Department $department): bool { return $user->hasPermission('departments.view', $department->college_id); }
    public function create(User $user): bool { return $user->hasPermission('departments.create'); }
    public function update(User $user, Department $department): bool { return $user->hasPermission('departments.update', $department->college_id); }
    public function delete(User $user, Department $department): bool { return $user->hasPermission('departments.delete', $department->college_id); }
}
