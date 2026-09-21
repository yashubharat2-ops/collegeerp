<?php

namespace App\Policies;

use App\Models\GradeScale;
use App\Models\User;

/**
 * Grade / Pass-Fail configuration authorization (Examinations Phase 3).
 *
 * Follows the existing RBAC conventions: permission slugs are checked via
 * User::hasPermission (tenant-aware), so a permission granted in another
 * college never authorises an action in the active one.
 */
class GradeScalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('grade_scales.view');
    }

    public function view(User $user, GradeScale $gradeScale): bool
    {
        return $user->hasPermission('grade_scales.view', $gradeScale->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('grade_scales.create');
    }

    public function update(User $user, GradeScale $gradeScale): bool
    {
        return $user->hasPermission('grade_scales.update', $gradeScale->college_id);
    }

    public function delete(User $user, GradeScale $gradeScale): bool
    {
        return $user->hasPermission('grade_scales.delete', $gradeScale->college_id);
    }
}
