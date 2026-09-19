<?php

namespace App\Policies;

use App\Models\StudentAcademicRecord;
use App\Models\User;

/**
 * RBAC for student academic records.
 *
 * Follows the project's convention exactly: policies only translate permission
 * slugs into allow/deny, scoped to the record's own college_id for instance
 * actions. There is no Super Admin bypass and no role branching here —
 * User::hasPermission() already requires an active role assignment in that
 * college (and, for the seeded super-admin role, an active permission row).
 */
class StudentAcademicRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_academic_records.view');
    }

    public function view(User $user, StudentAcademicRecord $record): bool
    {
        return $user->hasPermission('student_academic_records.view', $record->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_academic_records.create');
    }

    public function update(User $user, StudentAcademicRecord $record): bool
    {
        return $user->hasPermission('student_academic_records.update', $record->college_id);
    }

    public function delete(User $user, StudentAcademicRecord $record): bool
    {
        return $user->hasPermission('student_academic_records.delete', $record->college_id);
    }
}
