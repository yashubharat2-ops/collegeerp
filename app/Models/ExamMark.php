<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ExamMark — marks captured for one eligible StudentEnrollment against one
 * ExamSchedule (Examinations Phase 2).
 *
 * Phase 2 stops at data capture: statuses are draft / entered / absent /
 * withheld. Result calculation, publishing, marksheets and grade cards are
 * later phases and are intentionally NOT implemented here.
 *
 * Absent / withheld decision (documented for Phase 3 reuse):
 * - obtained_marks stays NULL when status is absent or withheld; the row
 *   keeps max_marks / passing_marks so later result phases have the paper's
 *   scale even when no score exists.
 * - entered_at / entered_by are stamped whenever a record leaves the draft
 *   state (entered, absent and withheld are all final marker decisions).
 *
 * Student, program, section, subject, academic year and term are derived
 * through relationships — never duplicated onto this record.
 *
 * Uniqueness: at most one ACTIVE row per (college_id, exam_schedule_id,
 * student_enrollment_id); re-saving updates the existing row.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class ExamMark extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ENTERED = 'entered';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_WITHHELD = 'withheld';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ENTERED,
        self::STATUS_ABSENT,
        self::STATUS_WITHHELD,
    ];

    /**
     * Statuses that represent a final marker decision (no numeric score is
     * expected for them, so obtained_marks remains NULL).
     */
    public const SCORELESS_STATUSES = [
        self::STATUS_ABSENT,
        self::STATUS_WITHHELD,
    ];

    protected $fillable = [
        'college_id',
        'exam_schedule_id',
        'student_enrollment_id',
        'max_marks',
        'passing_marks',
        'obtained_marks',
        'remarks',
        'status',
        'entered_at',
        'entered_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
            'passing_marks' => 'decimal:2',
            'obtained_marks' => 'decimal:2',
            'entered_at' => 'datetime',
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

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
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
