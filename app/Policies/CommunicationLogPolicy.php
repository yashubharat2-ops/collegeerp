<?php

namespace App\Policies;

use App\Models\CommunicationLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * SMS / e-mail log authorization (Communication Management, Phase 2).
 *
 * Logs are IMMUTABLE from the UI: only read abilities exist, so no
 * create / update / delete permission is defined for them. Record reads
 * additionally require the log to belong to the ACTIVE college.
 */
class CommunicationLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication_logs.view');
    }

    public function view(User $user, CommunicationLog $log): bool
    {
        $active = app(TenantContext::class)->id();

        return $active !== null
            && (int) $log->college_id === (int) $active
            && $user->hasPermission('communication_logs.view', (int) $log->college_id);
    }

    /** Logs are never created, edited or deleted through the UI. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CommunicationLog $log): bool
    {
        return false;
    }

    public function delete(User $user, CommunicationLog $log): bool
    {
        return false;
    }
}
