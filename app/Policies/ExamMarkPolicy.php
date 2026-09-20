<?php

namespace App\Policies;

use App\Models\ExamMark;
use App\Models\ExamSchedule;
use App\Models\User;

/**
 * Marks Entry authorization (Examinations Phase 2).
 *
 * Follows the existing RBAC conventions: permission slugs are checked via
 * User::hasPermission (tenant-aware), and the completed-schedule lock from
 * ExamSchedulePolicy is honoured — once the parent schedule is completed,
 * only the global Super Admin may alter marks. Phase 2 does not introduce a
 * result-publishing workflow of its own.
 */
class ExamMarkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exam_marks.view');
    }

    public function view(User $user, ExamMark $examMark): bool
    {
        return $user->hasPermission('exam_marks.view', $examMark->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exam_marks.create');
    }

    public function update(User $user, ExamMark $examMark): bool
    {
        if ($this->scheduleLocked($user, $examMark)) {
            return false;
        }

        return $user->hasPermission('exam_marks.update', $examMark->college_id);
    }

    public function delete(User $user, ExamMark $examMark): bool
    {
        if ($this->scheduleLocked($user, $examMark)) {
            return false;
        }

        return $user->hasPermission('exam_marks.delete', $examMark->college_id);
    }

    /**
     * Mirrors ExamSchedulePolicy: records belonging to a completed schedule
     * are finalized for everyone except the global Super Admin.
     */
    private function scheduleLocked(User $user, ExamMark $examMark): bool
    {
        $status = $examMark->examSchedule?->status;

        return $status === ExamSchedule::STATUS_COMPLETED && ! $user->isSuperAdmin();
    }
}
