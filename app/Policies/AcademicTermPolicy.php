<?php

namespace App\Policies;

use App\Models\AcademicTerm;
use App\Models\User;

class AcademicTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('academic_terms.view');
    }

    public function view(User $user, AcademicTerm $academicTerm): bool
    {
        return $user->hasPermission('academic_terms.view', $academicTerm->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('academic_terms.create');
    }

    public function update(User $user, AcademicTerm $academicTerm): bool
    {
        return $user->hasPermission('academic_terms.update', $academicTerm->college_id);
    }

    public function delete(User $user, AcademicTerm $academicTerm): bool
    {
        return $user->hasPermission('academic_terms.delete', $academicTerm->college_id);
    }
}
