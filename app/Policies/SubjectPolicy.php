<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('subjects.view');
    }

    public function view(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.view', $subject->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('subjects.create');
    }

    public function update(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.update', $subject->college_id);
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.delete', $subject->college_id);
    }
}
