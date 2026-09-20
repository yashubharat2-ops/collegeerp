<?php

namespace App\Policies;

use App\Models\ExamAttendance;
use App\Models\ExamSchedule;
use App\Models\User;

/**
 * Exam Attendance authorization (Examinations Phase 2).
 *
 * Follows the existing RBAC conventions: permission slugs are checked via
 * User::hasPermission (tenant-aware), and the completed-schedule lock from
 * ExamSchedulePolicy is honoured — once the parent schedule is completed,
 * only the global Super Admin may alter attendance records.
 */
class ExamAttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exam_attendance.view');
    }

    public function view(User $user, ExamAttendance $examAttendance): bool
    {
        return $user->hasPermission('exam_attendance.view', $examAttendance->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exam_attendance.create');
    }

    public function update(User $user, ExamAttendance $examAttendance): bool
    {
        if ($this->scheduleLocked($user, $examAttendance)) {
            return false;
        }

        return $user->hasPermission('exam_attendance.update', $examAttendance->college_id);
    }

    public function delete(User $user, ExamAttendance $examAttendance): bool
    {
        if ($this->scheduleLocked($user, $examAttendance)) {
            return false;
        }

        return $user->hasPermission('exam_attendance.delete', $examAttendance->college_id);
    }

    /**
     * Mirrors ExamSchedulePolicy: records belonging to a completed schedule
     * are finalized for everyone except the global Super Admin. Phase 2 does
     * not introduce any new locking workflow of its own.
     */
    private function scheduleLocked(User $user, ExamAttendance $examAttendance): bool
    {
        $status = $examAttendance->examSchedule?->status;

        return $status === ExamSchedule::STATUS_COMPLETED && ! $user->isSuperAdmin();
    }
}
