<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDocument;

/**
 * RBAC for vehicle documents.
 *
 * Permission set is intentionally small (view / create / update / delete):
 * download is a `view`-level action and file replacement is an `update`-level
 * action, mirroring StudentDocumentPolicy / EmployeeDocumentPolicy.
 */
class VehicleDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vehicle_documents.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicle_documents.create');
    }

    public function view(User $user, VehicleDocument $document): bool
    {
        return $this->allowed($user, $document, 'view');
    }

    public function update(User $user, VehicleDocument $document): bool
    {
        return $this->allowed($user, $document, 'update');
    }

    public function delete(User $user, VehicleDocument $document): bool
    {
        return $this->allowed($user, $document, 'delete');
    }

    /** Downloads require the same permission as viewing the record. */
    public function download(User $user, VehicleDocument $document): bool
    {
        return $this->allowed($user, $document, 'view');
    }

    private function allowed(User $user, VehicleDocument $document, string $action): bool
    {
        return (int) $document->college_id === app(\App\Support\Tenancy\TenantContext::class)->id()
            && $user->hasPermission('vehicle_documents.'.$action, $document->college_id);
    }
}
