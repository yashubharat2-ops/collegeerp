<?php

namespace App\Policies;

use App\Models\LibraryRenewal;
use App\Models\User;

/**
 * Renewal authorization (Library Management).
 *
 * Renewals are append-only: view and create only. There is no update or delete.
 */
class LibraryRenewalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('library_renewals.view');
    }

    public function view(User $user, LibraryRenewal $renewal): bool
    {
        return $user->hasPermission('library_renewals.view', $renewal->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('library_renewals.create');
    }

    public function update(User $user, LibraryRenewal $renewal): bool
    {
        return false;
    }

    public function delete(User $user, LibraryRenewal $renewal): bool
    {
        return false;
    }
}
