<?php

namespace App\Policies;

use App\Models\Author;
use App\Models\User;

/**
 * Author authorization (Library Management).
 */
class AuthorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('authors.view');
    }

    public function view(User $user, Author $author): bool
    {
        return $user->hasPermission('authors.view', $author->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('authors.create');
    }

    public function update(User $user, Author $author): bool
    {
        return $user->hasPermission('authors.update', $author->college_id);
    }

    public function delete(User $user, Author $author): bool
    {
        return $user->hasPermission('authors.delete', $author->college_id);
    }
}
