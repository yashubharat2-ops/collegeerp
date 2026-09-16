<?php

namespace App\Policies;

use App\Models\AdmissionMeritEntry;
use App\Models\User;

class AdmissionMeritEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_merit.view');
    }

    public function view(User $user, AdmissionMeritEntry $entry): bool
    {
        return $user->hasPermission('admission_merit.view', $entry->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_merit.create');
    }

    public function update(User $user, AdmissionMeritEntry $entry): bool
    {
        return $user->hasPermission('admission_merit.update', $entry->college_id);
    }

    public function delete(User $user, AdmissionMeritEntry $entry): bool
    {
        return $user->hasPermission('admission_merit.delete', $entry->college_id);
    }
}
