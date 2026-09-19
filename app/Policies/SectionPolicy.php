<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sections.view');
    }

    public function view(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.view', $section->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('sections.create');
    }

    public function update(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.update', $section->college_id);
    }

    public function delete(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.delete', $section->college_id);
    }
}
