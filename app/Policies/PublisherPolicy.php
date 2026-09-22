<?php

namespace App\Policies;

use App\Models\Publisher;
use App\Models\User;

/**
 * Publisher authorization (Library Management).
 */
class PublisherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('publishers.view');
    }

    public function view(User $user, Publisher $publisher): bool
    {
        return $user->hasPermission('publishers.view', $publisher->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('publishers.create');
    }

    public function update(User $user, Publisher $publisher): bool
    {
        return $user->hasPermission('publishers.update', $publisher->college_id);
    }

    public function delete(User $user, Publisher $publisher): bool
    {
        return $user->hasPermission('publishers.delete', $publisher->college_id);
    }
}
