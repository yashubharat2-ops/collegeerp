<?php

namespace App\Policies;

use App\Models\BookCopy;
use App\Models\User;

/**
 * Book copy authorization (Library Management).
 *
 * Permission slugs are checked through User::hasPermission, which is
 * tenant-aware: a permission granted in another college never authorises an
 * action in the active one.
 */
class BookCopyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('book_copies.view');
    }

    public function view(User $user, BookCopy $copy): bool
    {
        return $user->hasPermission('book_copies.view', $copy->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('book_copies.create');
    }

    public function update(User $user, BookCopy $copy): bool
    {
        return $user->hasPermission('book_copies.update', $copy->college_id);
    }

    public function delete(User $user, BookCopy $copy): bool
    {
        return $user->hasPermission('book_copies.delete', $copy->college_id);
    }
}
