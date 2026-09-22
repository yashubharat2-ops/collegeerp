<?php

namespace App\Policies;

use App\Models\LibraryMember;
use App\Models\User;

/**
 * Library member authorization (Library Management).
 */
class LibraryMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('library_members.view');
    }

    public function view(User $user, LibraryMember $member): bool
    {
        return $user->hasPermission('library_members.view', $member->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('library_members.create');
    }

    public function update(User $user, LibraryMember $member): bool
    {
        return $user->hasPermission('library_members.update', $member->college_id);
    }

    public function delete(User $user, LibraryMember $member): bool
    {
        return $user->hasPermission('library_members.delete', $member->college_id);
    }
}
