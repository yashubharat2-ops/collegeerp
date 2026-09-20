<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ExamAttendance — presence record for one eligible StudentEnrollment against
 * one ExamSchedule (Examinations Phase 2).
 *
 * Architecture: ExamSchedule → eligible StudentEnrollments → ExamAttendance.
 * No student master data is copied onto this record: the student, program,
 * section, subject, academic year and term are always derived through the
 * examSchedule and studentEnrollment relationships.
 *
 * Uniqueness: at most one ACTIVE row per (college_id, exam_schedule_id,
 * student_enrollment_id). Re-marking updates the existing row
 * (updateOrCreate-style), and soft-deleted rows are preserved as history.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class ExamAttendance extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_LATE = 'late';
    public const STATUS_EXCUSED = 'excused';

    /**
     * Extensible status list — institutions may add statuses through the same
     * constant/validation pattern without schema changes.
     */
    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
        self::STATUS_EXCUSED,
    ];

    protected $fillable = [
        'college_id',
        'exam_schedule_id',
        'student_enrollment_id',
        'attendance_status',
        'marked_at',
        'remarks',
        'marked_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
        ];
    }

    public function examSchedule(): BelongsTo
    {
        return $this->belongsTo(ExamSchedule::class);
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function markedBy(): BelongsTo
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
