<?php

namespace App\Policies;

use App\Models\StudentFeeAssignment;
use App\Models\User;

/**
 * Student Fee Assignment authorization (Finance / Fees).
 */
class StudentFeeAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_fee_assignments.view');
    }

    public function view(User $user, StudentFeeAssignment $assignment): bool
    {
        return $user->hasPermission('student_fee_assignments.view', $assignment->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_fee_assignments.create');
    }

    public function update(User $user, StudentFeeAssignment $assignment): bool
    {
        return $user->hasPermission('student_fee_assignments.update', $assignment->college_id);
    }

    public function delete(User $user, StudentFeeAssignment $assignment): bool
    {
        return $user->hasPermission('student_fee_assignments.delete', $assignment->college_id);
    }
}
