<?php

namespace App\Policies;

use App\Models\User;
use App\Models\HrReport;

class HrReportPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('hr_reports.view'); }
}
