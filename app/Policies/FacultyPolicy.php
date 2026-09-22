<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;

/**
 * Platform Faculty/Staff and HR Employee policy.
 *
 * HR routes use the same Faculty records and the canonical faculties.*
 * permission family. No duplicate employees.* permission boundary is used.
 */
class FacultyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Faculty $faculty): bool
    {
        return $this->allows($user, 'view', $faculty->college_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Faculty $faculty): bool
    {
        return $this->allows($user, 'update', $faculty->college_id);
    }

    public function delete(User $user, Faculty $faculty): bool
    {
        return $this->allows($user, 'delete', $faculty->college_id);
    }

    private function allows(User $user, string $action, ?int $collegeId = null): bool
    {
        return $user->hasPermission('faculties.'.$action, $collegeId);
    }
}
