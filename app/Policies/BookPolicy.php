<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

/**
 * Book authorization (Library Management).
 *
 * Follows the existing RBAC conventions: permission slugs are checked through
 * User::hasPermission (tenant-aware), so a permission granted in another college
 * never authorises an action in the active one.
 */
class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('books.view');
    }

    public function view(User $user, Book $book): bool
    {
        return $user->hasPermission('books.view', $book->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('books.create');
    }

    public function update(User $user, Book $book): bool
    {
        return $user->hasPermission('books.update', $book->college_id);
    }

    public function delete(User $user, Book $book): bool
    {
        return $user->hasPermission('books.delete', $book->college_id);
    }
}
