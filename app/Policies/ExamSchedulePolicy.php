<?php

namespace App\Policies;

use App\Models\ExamSchedule;
use App\Models\User;

class ExamSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exam_schedules.view');
    }

    public function view(User $user, ExamSchedule $examSchedule): bool
    {
        return $user->hasPermission('exam_schedules.view', $examSchedule->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exam_schedules.create');
    }

    public function update(User $user, ExamSchedule $examSchedule): bool
    {
        if ($examSchedule->status === ExamSchedule::STATUS_COMPLETED && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermission('exam_schedules.update', $examSchedule->college_id);
    }

    public function delete(User $user, ExamSchedule $examSchedule): bool
    {
        if ($examSchedule->status === ExamSchedule::STATUS_COMPLETED && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermission('exam_schedules.delete', $examSchedule->college_id);
    }
}
