<?php

namespace App\Policies;

use App\Models\StudentResultHistory;
use App\Models\User;

/**
 * Student Result History authorization (Examinations Phase 4).
 *
 * The history timeline is derived from a student's published ExamResult rows,
 * so the policy has a single dedicated permission:
 *
 *   student_result_history.view → view a student's examination timeline
 *
 * Tenant isolation and soft-delete exclusion are enforced upstream by the
 * tenant-scoped Student query (CollegeScope + SoftDeletes), which turns
 * cross-college or deleted ids into a 404 before the policy is reached; the
 * timeline query itself only ever reads published results.
 */
class StudentResultHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_result_history.view');
    }

    /**
     * A student's timeline is viewable when the user holds the history
     * permission for the student's own college.
     */
    public function view(User $user, StudentResultHistory $history): bool
    {
        return $user->hasPermission('student_result_history.view', $history->student->college_id);
    }
}
