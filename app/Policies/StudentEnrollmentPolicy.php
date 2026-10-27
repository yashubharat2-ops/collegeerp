<?php

namespace App\Policies;

use App\Models\StudentEnrollment;
use App\Models\User;

class StudentEnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_enrollments.view');
    }

    public function view(User $user, StudentEnrollment $enrollment): bool
    {
        return $user->hasPermission('student_enrollments.view', $enrollment->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_enrollments.create');
    }

    public function update(User $user, StudentEnrollment $enrollment): bool
    {
        return $user->hasPermission('student_enrollments.update', $enrollment->college_id);
    }

    /**
     * Decide a pending enrollment (approve / reject).
     *
     * Deliberately a separate permission from update: recording an enrollment is
     * clerical work, while deciding that it becomes operational is a control.
     * The approval framework additionally refuses a decision on one's own
     * request unless the decider is a trusted authority of the college.
     */
    public function approve(User $user, StudentEnrollment $enrollment): bool
    {
        return $user->hasPermission('student_enrollments.approve', $enrollment->college_id);
    }

    public function delete(User $user, StudentEnrollment $enrollment): bool
    {
        return $user->hasPermission('student_enrollments.delete', $enrollment->college_id);
    }
}
