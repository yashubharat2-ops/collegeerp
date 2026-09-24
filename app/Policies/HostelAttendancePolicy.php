<?php

namespace App\Policies;

use App\Models\HostelAttendance;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Hostel Attendance authorization (Hostel Management Phase 3).
 */
class HostelAttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hostel_attendance.view');
    }

    public function view(User $user, HostelAttendance $attendance): bool
    {
        return $this->sameCollege($attendance)
            && $user->hasPermission('hostel_attendance.view', $attendance->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hostel_attendance.create');
    }

    public function update(User $user, HostelAttendance $attendance): bool
    {
        return $this->sameCollege($attendance)
            && $user->hasPermission('hostel_attendance.update', $attendance->college_id);
    }

    public function delete(User $user, HostelAttendance $attendance): bool
    {
        return $this->sameCollege($attendance)
            && $user->hasPermission('hostel_attendance.delete', $attendance->college_id);
    }

    private function sameCollege(HostelAttendance $attendance): bool
    {
        return (int) $attendance->college_id === (int) app(TenantContext::class)->id();
    }
}
