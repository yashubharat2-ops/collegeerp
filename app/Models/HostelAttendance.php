<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelAttendance — one daily mark for a hostel resident (Phase 3).
 *
 * The resident is an existing StudentEnrollment reached through an existing
 * HostelAllocation. Neither student nor hostel master data is copied onto the
 * row. college_id, marked_by and the audit columns are stamped server-side.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class HostelAttendance extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LEAVE = 'leave';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LEAVE,
    ];

    protected $fillable = [
        'college_id',
        'student_enrollment_id',
        'hostel_allocation_id',
        'attendance_date',
        'attendance_status',
        'remarks',
        'marked_at',
        'marked_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'marked_at' => 'datetime',
        ];
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(HostelAllocation::class, 'hostel_allocation_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
