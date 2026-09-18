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

    public function delete(User $user, StudentEnrollment $enrollment): bool
    {
        return $user->hasPermission('student_enrollments.delete', $enrollment->college_id);
    }
}
