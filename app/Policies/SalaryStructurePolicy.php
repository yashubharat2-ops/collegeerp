<?php

namespace App\Policies;

use App\Models\SalaryStructure;
use App\Models\User;

class SalaryStructurePolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('salary_structures.view'); }
    public function view(User $user, SalaryStructure $structure): bool { return $user->hasPermission('salary_structures.view', $structure->college_id); }
    public function create(User $user): bool { return $user->hasPermission('salary_structures.create'); }
    public function update(User $user, SalaryStructure $structure): bool { return $user->hasPermission('salary_structures.update', $structure->college_id); }
    public function delete(User $user, SalaryStructure $structure): bool { return $user->hasPermission('salary_structures.delete', $structure->college_id); }
}
