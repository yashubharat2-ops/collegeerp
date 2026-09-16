<?php

namespace App\Policies;

use App\Models\Admission;
use App\Models\User;

class AdmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admissions.view');
    }

    public function view(User $user, Admission $admission): bool
    {
        return $user->hasPermission('admissions.view', $admission->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admissions.create');
    }

    public function update(User $user, Admission $admission): bool
    {
        return $user->hasPermission('admissions.update', $admission->college_id);
    }

    public function delete(User $user, Admission $admission): bool
    {
        return $user->hasPermission('admissions.delete', $admission->college_id);
    }
}
