<?php

namespace App\Policies;

use App\Models\User;

/**
 * Certificate Reports authorization.
 *
 * Read-only screen with no resource of its own: a single screen-level
 * permission, `certificate_reports.view`. Nothing on the screen writes and
 * no reporting table exists.
 */
class CertificateReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('certificate_reports.view');
    }
}
