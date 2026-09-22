<?php

namespace App\Domain\HR\Services;

use App\Models\Faculty;
use App\Models\StaffAttendance;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffAttendanceService
{
    private const AUDITED = ['id', 'faculty_id', 'attendance_date', 'status', 'remarks'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function create(array $data, int $collegeId, ?int $userId): StaffAttendance
    {
        abort_unless((int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey() === $collegeId, 403);
        $this->assertEmployee($collegeId, (int) $data['faculty_id']);
        try {
            $attendance = DB::transaction(fn () => StaffAttendance::create([
                'college_id' => $collegeId,
                'faculty_id' => $data['faculty_id'],
                'attendance_date' => $data['attendance_date'],
                'status' => $data['status'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Attendance for this employee and date already exists. Edit the existing record instead.',
            ]);
        }

        $this->audit->record('staff_attendance.created', $attendance, [], $attendance->only(self::AUDITED));

        return $attendance;
    }

    public function update(StaffAttendance $attendance, array $data, ?int $userId): StaffAttendance
    {
        if ((int) $attendance->college_id !== (int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey()) abort(404);
        $this->assertEmployee((int) $attendance->college_id, (int) $data['faculty_id']);
        $old = $attendance->only(self::AUDITED);
        try {
            DB::transaction(fn () => $attendance->update([
                'faculty_id' => $data['faculty_id'],
                'attendance_date' => $data['attendance_date'],
                'status' => $data['status'],
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => $userId,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Attendance for this employee and date already exists.',
            ]);
        }

        $this->audit->record('staff_attendance.updated', $attendance, $old, $attendance->fresh()->only(self::AUDITED));

        return $attendance->refresh();
    }

    public function delete(StaffAttendance $attendance, ?int $userId): void
    {
        if ((int) $attendance->college_id !== (int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey()) abort(404);
        $snapshot = $attendance->only(self::AUDITED);
        $attendance->update(['updated_by' => $userId]);
        $attendance->delete();
        $this->audit->record('staff_attendance.deleted', $attendance, $snapshot, []);
    }

    private function assertEmployee(int $collegeId, int $facultyId): void
    {
        abort_unless(
            Faculty::withoutGlobalScopes()->where('college_id', $collegeId)->whereKey($facultyId)->whereNull('deleted_at')->exists(),
            404,
            'Employee not found in this college context.'
        );
    }
}
