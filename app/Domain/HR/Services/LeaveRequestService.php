<?php

namespace App\Domain\HR\Services;

use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Audit\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    private const AUDITED = [
        'id', 'faculty_id', 'leave_type_id', 'from_date', 'to_date', 'days',
        'reason', 'status', 'approval_remarks', 'requested_by', 'approved_by', 'approved_at', 'rejected_at',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function create(array $data, int $collegeId, ?int $userId): LeaveRequest
    {
        abort_unless((int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey() === $collegeId, 403);
        $this->assertEmployeeAndLeaveType($collegeId, (int) $data['faculty_id'], (int) $data['leave_type_id']);
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $this->assertDateRange($from, $to);
        $this->assertNoApprovedOverlap($collegeId, (int) $data['faculty_id'], $from, $to);

        $request = DB::transaction(fn () => LeaveRequest::create([
            'college_id' => $collegeId,
            'faculty_id' => $data['faculty_id'],
            'leave_type_id' => $data['leave_type_id'],
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
            'reason' => $data['reason'],
            'status' => 'pending',
            'requested_by' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));

        $this->audit->record('leave_request.created', $request, [], $request->only(self::AUDITED));

        return $request;
    }

    public function update(LeaveRequest $request, array $data, ?int $userId): LeaveRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'Only pending leave requests can be edited.']);
        }

        $this->assertEmployeeAndLeaveType((int) $request->college_id, (int) $data['faculty_id'], (int) $data['leave_type_id']);
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $this->assertDateRange($from, $to);
        $this->assertNoApprovedOverlap((int) $request->college_id,  (int) $data['faculty_id'], $from, $to, $request->id);

        $old = $request->only(self::AUDITED);
        $request->update([
            'faculty_id' => $data['faculty_id'],
            'leave_type_id' => $data['leave_type_id'],
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
            'reason' => $data['reason'],
            'updated_by' => $userId,
        ]);
        $this->audit->record('leave_request.updated', $request, $old, $request->fresh()->only(self::AUDITED));

        return $request->refresh();
    }

    public function approve(LeaveRequest $request, int $collegeId, ?int $approverId, ?string $remarks = null): LeaveRequest
    {
        if ((int) $request->college_id !== $collegeId) {
            abort(403);
        }
        $old = $request->only(self::AUDITED);
        $request = DB::transaction(function () use ($request, $collegeId, $approverId, $remarks): LeaveRequest {
            $locked = LeaveRequest::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => 'Only pending leave requests can be approved.']);
            }
            Faculty::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($locked->faculty_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertNoApprovedOverlap($collegeId, (int) $locked->faculty_id, $locked->from_date, $locked->to_date, $locked->id);
            $locked->update([
                'status' => 'approved',
                'approval_remarks' => $remarks,
                'approved_by' => $approverId,
                'approved_at' => now(),
                'rejected_at' => null,
                'updated_by' => $approverId,
            ]);
            return $locked;
        });
        $this->audit->record('leave_request.approved', $request, $old, $request->fresh()->only(self::AUDITED));

        return $request->refresh();
    }

    public function reject(LeaveRequest $request, int $collegeId, ?int $approverId, ?string $remarks = null): LeaveRequest
    {
        if ((int) $request->college_id !== $collegeId) {
            abort(403);
        }
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'Only pending leave requests can be rejected.']);
        }

        $old = $request->only(self::AUDITED);
        $request->update([
            'status' => 'rejected',
            'approval_remarks' => $remarks,
            'approved_by' => $approverId,
            'approved_at' => null,
            'rejected_at' => now(),
            'updated_by' => $approverId,
        ]);
        $this->audit->record('leave_request.rejected', $request, $old, $request->fresh()->only(self::AUDITED));

        return $request->refresh();
    }

    public function cancel(LeaveRequest $request, int $collegeId, ?int $userId): LeaveRequest
    {
        if ((int) $request->college_id !== $collegeId) {
            abort(403);
        }
        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'This leave request cannot be cancelled.']);
        }

        $old = $request->only(self::AUDITED);
        $request->update(['status' => 'cancelled', 'updated_by' => $userId]);
        $this->audit->record('leave_request.cancelled', $request, $old, $request->fresh()->only(self::AUDITED));

        return $request->refresh();
    }

    public function delete(LeaveRequest $request, ?int $userId): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'Only pending leave requests can be deleted.']);
        }

        $snapshot = $request->only(self::AUDITED);
        $request->update(['updated_by' => $userId]);
        $request->delete();
        $this->audit->record('leave_request.deleted', $request, $snapshot, []);
    }

    private function assertEmployeeAndLeaveType(int $collegeId, int $facultyId, int $leaveTypeId): void
    {
        abort_unless(
            Faculty::withoutGlobalScopes()->where('college_id', $collegeId)->whereKey($facultyId)->whereNull('deleted_at')->exists(),
            404,
            'Employee not found in this college context.'
        );
        abort_unless(
            LeaveType::withoutGlobalScopes()->where('college_id', $collegeId)->whereKey($leaveTypeId)->whereNull('deleted_at')->where('status', 'active')->exists(),
            404,
            'Leave type not found in this college context.'
        );
    }

    private function assertDateRange(Carbon $from, Carbon $to): void
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to_date' => 'The end date must be on or after the start date.']);
        }
    }

    private function assertNoApprovedOverlap(int $collegeId, int $facultyId, Carbon $from, Carbon $to, ?int $ignoreId = null): void
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $overlap = LeaveRequest::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('faculty_id', $facultyId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->where('from_date', '<=', $toDate)
            ->where('to_date', '>=', $fromDate)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'from_date' => 'This employee already has approved leave overlapping the selected dates.',
            ]);
        }
    }
}
