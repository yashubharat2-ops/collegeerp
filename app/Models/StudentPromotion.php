<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentPromotion — the auditable decision to move a student forward.
 *
 * Promotion is additive by design. Approving a promotion creates a new
 * StudentEnrollment for the target academic year and flips the source
 * enrollment's status to `completed`; the source row, its enrollment number
 * and its audit trail are always preserved.
 *
 * No progression rule is encoded here: the target academic year, program,
 * term and section are the operator's choice, so institutions decide their own
 * promotion policy (annual, semester, lateral entry, re-admission).
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class StudentPromotion extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUSES = ['pending', 'approved', 'cancelled'];

    protected $fillable = [
        'college_id',
        'student_id',
        'source_enrollment_id',
        'source_academic_year_id',
        'source_program_id',
        'source_section_id',
        'target_academic_year_id',
        'target_program_id',
        'target_academic_term_id',
        'target_section_id',
        'target_enrollment_id',
        'effective_date',
        'status',
        'remarks',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'source_enrollment_id');
    }

    public function targetEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'target_enrollment_id');
    }

    public function sourceAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'source_academic_year_id');
    }

    public function sourceProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'source_program_id');
    }

    public function sourceSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'source_section_id');
    }

    public function targetAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'target_academic_year_id');
    }

    public function targetProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'target_program_id');
    }

    public function targetAcademicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'target_academic_term_id');
    }

    public function targetSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'target_section_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
