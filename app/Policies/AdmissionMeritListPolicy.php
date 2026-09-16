<?php

namespace App\Policies;

use App\Models\AdmissionMeritList;
use App\Models\User;

class AdmissionMeritListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_merit.view');
    }

    public function view(User $user, AdmissionMeritList $list): bool
    {
        return $user->hasPermission('admission_merit.view', $list->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_merit.create');
    }

    public function update(User $user, AdmissionMeritList $list): bool
    {
        return $user->hasPermission('admission_merit.update', $list->college_id);
    }

    public function delete(User $user, AdmissionMeritList $list): bool
    {
        return $user->hasPermission('admission_merit.delete', $list->college_id);
    }

    public function publish(User $user, AdmissionMeritList $list): bool
    {
        return $user->hasPermission('admission_merit.publish', $list->college_id);
    }
}
