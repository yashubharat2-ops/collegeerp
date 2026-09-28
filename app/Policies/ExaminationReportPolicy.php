<?php

namespace App\Policies;

use App\Models\User;

class ExaminationReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('examination_reports.view');
    }
}
