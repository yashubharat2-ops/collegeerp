<?php

namespace App\Policies;

use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * SMS / e-mail template authorization (Communication Management, Phase 2).
 *
 * Uses the centralized RBAC (User::hasPermission, tenant-aware; Super Admin
 * handled there). Record abilities additionally require the template to
 * belong to the ACTIVE college, so a permission held elsewhere can never act
 * on another college's template.
 */
class CommunicationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication_templates.view');
    }

    public function view(User $user, CommunicationTemplate $template): bool
    {
        return $this->allowed($user, $template, 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('communication_templates.create');
    }

    public function update(User $user, CommunicationTemplate $template): bool
    {
        return $this->allowed($user, $template, 'update');
    }

    public function delete(User $user, CommunicationTemplate $template): bool
    {
        return $this->allowed($user, $template, 'delete');
    }

    private function allowed(User $user, CommunicationTemplate $template, string $action): bool
    {
        $active = app(TenantContext::class)->id();

        return $active !== null
            && (int) $template->college_id === (int) $active
            && $user->hasPermission('communication_templates.'.$action, (int) $template->college_id);
    }
}
