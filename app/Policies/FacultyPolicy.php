<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;

class FacultyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('faculties.view');
    }

    public function view(User $user, Faculty $faculty): bool
    {
        return $user->hasPermission('faculties.view', $faculty->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('faculties.create');
    }

    public function update(User $user, Faculty $faculty): bool
    {
        return $user->hasPermission('faculties.update', $faculty->college_id);
    }

    public function delete(User $user, Faculty $faculty): bool
    {
        return $user->hasPermission('faculties.delete', $faculty->college_id);
    }
}
