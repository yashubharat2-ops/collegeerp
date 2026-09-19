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

    public const STATUSES = ['active', 'completed', 'cancelled', 'withdrawn'];

    protected $fillable = [
        'college_id',
        'student_id',
        'academic_year_id',
        'program_id',
        'section_id',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
