<?php

namespace App\Policies;

use App\Models\AdmissionApplication;
use App\Models\User;

class AdmissionApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_applications.view');
    }

    public function view(User $user, AdmissionApplication $application): bool
    {
        return $user->hasPermission('admission_applications.view', $application->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_applications.create');
    }

    public function update(User $user, AdmissionApplication $application): bool
    {
        return $user->hasPermission('admission_applications.update', $application->college_id);
    }

    public function delete(User $user, AdmissionApplication $application): bool
    {
        return $user->hasPermission('admission_applications.delete', $application->college_id);
    }
}
