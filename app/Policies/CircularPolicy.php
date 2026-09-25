<?php

namespace App\Policies;

use App\Models\Circular;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Circulars authorization (Communication Management).
 *
 * Same conventions as NoticePolicy with the `circulars.*` permission set:
 * record abilities require the circular to belong to the active college;
 * downloads are `view`-level; publish / unpublish / archive share
 * `circulars.publish`.
 */
class CircularPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('circulars.view');
    }

    public function view(User $user, Circular $circular): bool
    {
        return $this->allowed($user, $circular, 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('circulars.create');
    }

    public function update(User $user, Circular $circular): bool
    {
        return $this->allowed($user, $circular, 'update');
    }

    public function delete(User $user, Circular $circular): bool
    {
        return $this->allowed($user, $circular, 'delete');
    }

    public function publish(User $user, Circular $circular): bool
    {
        return $this->allowed($user, $circular, 'publish');
    }

    public function download(User $user, Circular $circular): bool
    {
        return $this->allowed($user, $circular, 'view');
    }

    private function allowed(User $user, Circular $circular, string $action): bool
    {
        $active = app(TenantContext::class)->id();

        return $active !== null
            && (int) $circular->college_id === (int) $active
            && $user->hasPermission('circulars.'.$action, (int) $circular->college_id);
    }
}
