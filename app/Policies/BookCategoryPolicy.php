<?php

namespace App\Policies;

use App\Models\BookCategory;
use App\Models\User;

/**
 * Book Category authorization (Library Management).
 *
 * Follows the existing RBAC conventions: permission slugs are checked through
 * User::hasPermission (tenant-aware), so a permission granted in another college
 * never authorises an action in the active one.
 */
class BookCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('book_categories.view');
    }

    public function view(User $user, BookCategory $category): bool
    {
        return $user->hasPermission('book_categories.view', $category->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('book_categories.create');
    }

    public function update(User $user, BookCategory $category): bool
    {
        return $user->hasPermission('book_categories.update', $category->college_id);
    }

    public function delete(User $user, BookCategory $category): bool
    {
        return $user->hasPermission('book_categories.delete', $category->college_id);
    }
}
