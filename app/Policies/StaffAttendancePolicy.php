<?php

namespace App\Policies;

use App\Models\StaffAttendance;
use App\Models\User;

class StaffAttendancePolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('staff_attendance.view'); }
    public function view(User $user, StaffAttendance $attendance): bool { return $user->hasPermission('staff_attendance.view', $attendance->college_id); }
    public function create(User $user): bool { return $user->hasPermission('staff_attendance.create'); }
    public function update(User $user, StaffAttendance $attendance): bool { return $user->hasPermission('staff_attendance.update', $attendance->college_id); }
    public function delete(User $user, StaffAttendance $attendance): bool { return $user->hasPermission('staff_attendance.delete', $attendance->college_id); }
}
