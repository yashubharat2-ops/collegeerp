<?php

namespace App\Policies;

use App\Models\User;

class StudentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_reports.view');
    }
}
