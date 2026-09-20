<?php

namespace App\Policies;

use App\Models\Examination;
use App\Models\User;

class ExaminationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('examinations.view');
    }

    public function view(User $user, Examination $examination): bool
    {
        return $user->hasPermission('examinations.view', $examination->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('examinations.create');
    }

    public function update(User $user, Examination $examination): bool
    {
        if ($examination->status === Examination::STATUS_COMPLETED && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermission('examinations.update', $examination->college_id);
    }

    public function delete(User $user, Examination $examination): bool
    {
        if ($examination->status === Examination::STATUS_COMPLETED && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermission('examinations.delete', $examination->college_id);
    }
}
