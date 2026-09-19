<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentAcademicRecord — a student's academic standing for one period.
 *
 * Progression ledger for the Students module. It references (never copies) the
 * Platform's academic master data: AcademicYear, AcademicTerm, Program and
 * Section are all foreign keys resolved through CollegeScope, so a record can
 * never point at another college's year/term/program/section.
 *
 * One live record per student + academic year + academic term; the rule is
 * enforced transactionally in StudentAcademicRecordService, mirroring the
 * duplicate-active-enrollment protection in StudentService.
 *
 * Deliberately result-agnostic: no marks or grades live here. The future
 * Examination/Result module attaches to this record (and to the optional
 * academic_term_id) instead of introducing a parallel progression table.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class StudentAcademicRecord extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    /** Academic standing for the recorded period. */
    public const ACADEMIC_STATUSES = ['enrolled', 'passed', 'failed', 'withdrawn', 'discontinued'];

    /** Progression outcome for the recorded period. */
    public const PROMOTION_STATUSES = ['not_applicable', 'pending', 'promoted', 'retained', 'transferred'];

    /** Completion state of the program/stage. */
    public const COMPLETION_STATUSES = ['pending', 'completed', 'incomplete'];

    protected $fillable = [
        'college_id',
        'student_id',
        'enrollment_id',
        'academic_year_id',
        'academic_term_id',
        'program_id',
        'section_id',
        'academic_status',
        'promotion_status',
        'completion_status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'enrollment_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Human readable period label, e.g. "2026-27 · Semester 1".
     */
    public function periodLabel(): string
    {
        $year = $this->academicYear?->name ?? '—';

        return $this->academicTerm ? $year.' · '.$this->academicTerm->name : $year;
    }
}
