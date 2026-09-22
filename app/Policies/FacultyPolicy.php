<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;

/**
 * Platform Faculty/Staff and HR Employee policy.
 *
 * HR routes use the same Faculty records, so both the pre-existing faculty
 * permissions and the HR employee permissions are accepted during the additive
 * transition. No second staff table or authorization boundary is introduced.
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
        return $user->hasPermission('faculties.'.$action, $collegeId)
            || $user->hasPermission('employees.'.$action, $collegeId);
    }
}
