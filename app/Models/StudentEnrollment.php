<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentEnrollment — one periodic academic-year/program enrollment of a Student.
 *
 * Composes the student's historical academic record: a student accumulates an
 * enrollment per academic year (and optionally per program). Past enrollments
 * are soft-deleted/cancelled rather than overwritten so history is preserved
 * when the student moves to the next academic year.
 *
 * Tenant-safe relationship resolution: student/academicYear/program/section
 * all carry the CollegeScope global scope, so on a tenant-scoped query the
 * relationships resolve only to the same tenant and hydrate as null otherwise.
 */
class StudentEnrollment extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    /**
     * Lifecycle of one enrollment.
     *
     *     pending_approval --approve--> active --promotion/TC--> completed
     *                      --reject---> rejected
     *     active -----------cancel----> cancelled / withdrawn
     *
     * `pending_approval` and `rejected` are WORKFLOW states: they are reached
     * only through Approval Management (`student.enrollment_approval`), never by
     * picking them in the form or through a bulk status change. A pending row
     * already carries its generated Enrollment Number and Roll Number — the
     * numbers belong to the record, and approval only decides whether it becomes
     * operational.
     */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_REJECTED = 'rejected';

    /** Every stored status (validation, filtering, badges). */
    public const STATUSES = [
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_WITHDRAWN,
        self::STATUS_REJECTED,
    ];

    /**
     * Statuses an operator may pick directly (create/edit/bulk).
     *
     * `pending_approval` is requested through the approval gate and `rejected`
     * is the outcome of a rejection decision, so neither is an ordinary choice:
     * offering them would let a user bypass — or fake — the approval transition.
     */
    public const MANUAL_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_WITHDRAWN,
    ];

    /** Terminal states: an enrollment in one of these never becomes active again. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_WITHDRAWN,
        self::STATUS_REJECTED,
    ];

    /**
     * The statuses the create/edit form may offer: the manual ones plus whatever
     * the record already holds, so an edit never silently rewrites a workflow
     * state it did not choose.
     *
     * @return array<int, string>
     */
    public static function formStatuses(?string $current = null): array
    {
        $statuses = self::MANUAL_STATUSES;

        if ($current !== null && in_array($current, self::STATUSES, true) && ! in_array($current, $statuses, true)) {
            array_unshift($statuses, $current);
        }

        return $statuses;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** Human label for the list badge. */
    public function statusLabel(): string
    {
        return self::statusLabelFor((string) $this->status);
    }

    /** Label for any status value (used by forms that render the choices). */
    public static function statusLabelFor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_APPROVAL => 'Pending approval',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_WITHDRAWN => 'Withdrawn',
            self::STATUS_REJECTED => 'Rejected',
            default => ucfirst($status),
        };
    }

    public function certificates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Certificate::class, 'student_enrollment_id');
    }

    protected $fillable = [
        'college_id',
        'student_id',
        'academic_year_id',
        'program_id',
        'section_id',
        // Year-of-study / semester of this enrollment (e.g. "1", "Semester 3").
        'semester',
        // Class/section roll number for THIS enrollment. Unique per
        // (college, academic year, programme, section); a student's roll number
        // changes with the class, their Student ID never does.
        'roll_number',
        'enrollment_number',
        'enrollment_date',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_date' => 'date',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    /**
     * Optional Section / Batch the student sits in for this enrollment.
     *
     * Section master data is owned by the Platform module and is referenced,
     * never duplicated. A Section is always valid for exactly one academic
     * year + program pair, so the write side validates the section against the
     * enrollment's own year/program.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class, 'enrollment_id');
    }

    public function subjectEnrollments(): HasMany { return $this->hasMany(AcademicSubjectEnrollment::class, 'student_enrollment_id'); }

    /**
     * Exam attendance records for this enrollment (Examinations Phase 2).
     */
    public function examAttendances(): HasMany
    {
        return $this->hasMany(ExamAttendance::class);
    }

    /**
     * Exam marks captured for this enrollment (Examinations Phase 2).
     */
    public function examMarks(): HasMany
    {
        return $this->hasMany(ExamMark::class);
    }

    /** Fee plans assigned to this enrollment (Finance / Fees). */
    public function feeAssignments(): HasMany
    {
        return $this->hasMany(StudentFeeAssignment::class);
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
