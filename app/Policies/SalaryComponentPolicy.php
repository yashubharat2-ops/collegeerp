<?php

namespace App\Policies;

use App\Models\SalaryComponent;
use App\Models\User;

class SalaryComponentPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('salary_components.view'); }
    public function view(User $user, SalaryComponent $component): bool { return $user->hasPermission('salary_components.view', $component->college_id); }
    public function create(User $user): bool { return $user->hasPermission('salary_components.create'); }
    public function update(User $user, SalaryComponent $component): bool { return $user->hasPermission('salary_components.update', $component->college_id); }
    public function delete(User $user, SalaryComponent $component): bool { return $user->hasPermission('salary_components.delete', $component->college_id); }
}
