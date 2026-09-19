<?php

namespace App\Policies;

use App\Models\StudentPromotion;
use App\Models\User;

/**
 * RBAC for student promotions.
 *
 * Three permissions only: view, create (record a pending request) and approve
 * (execute it). Approving a promotion writes enrollments, so it is deliberately
 * a separate, higher privilege than creating the request. Cancelling a pending
 * request uses the same approve privilege, because both change what will happen
 * to a student's enrollment.
 */
class StudentPromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_promotions.view');
    }

    public function view(User $user, StudentPromotion $promotion): bool
    {
        return $user->hasPermission('student_promotions.view', $promotion->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_promotions.create');
    }

    /** Executing (or withdrawing) a pending promotion. */
    public function approve(User $user, StudentPromotion $promotion): bool
    {
        return $user->hasPermission('student_promotions.approve', $promotion->college_id);
    }
}
