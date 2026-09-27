<?php

namespace App\Policies;

use App\Models\User;

class AcademicReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('academic_reports.view');
    }
}
