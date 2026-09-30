<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy\TenantContext;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('audit_logs.view');
    }

    public function viewPlatform(User $user): bool
    {
        // Cross-college audit reads require an explicit platform view. Merely
        // being a Super Admin never broadens the default tenant query.
        return $user->isSuperAdmin() && $user->hasPermission('audit_logs.view');
    }
}
