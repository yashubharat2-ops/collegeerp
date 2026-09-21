<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use App\Services\Examinations\ResultRuleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ExamResult — the calculated result snapshot for one StudentEnrollment in one
 * Examination (Examinations Phase 3).
 *
 * Responsibility split (kept deliberately strict):
 *
 *   ExamMark  = SOURCE OF TRUTH for entered marks
 *   ExamResult / ExamResultItem = a RECALCULABLE SNAPSHOT produced by
 *                                 ResultCalculationService
 *
 * Nothing here is a second source of truth for marks, and no student /
 * program / section / subject / academic-year / academic-term data is copied
 * onto the record — all of it is derived through relationships.
 *
 * Three independent status dimensions, never overloaded into one field:
 *
 *   calculation_status : pending | calculated | incomplete | failed
 *   result_status      : pass | fail | absent | withheld | incomplete
 *   publication status : derived from published_at → published | unpublished
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class ExamResult extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    // --- calculation_status -------------------------------------------------
    public const CALCULATION_PENDING = 'pending';
    public const CALCULATION_CALCULATED = 'calculated';
    public const CALCULATION_INCOMPLETE = 'incomplete';
    public const CALCULATION_FAILED = 'failed';

    public const CALCULATION_STATUSES = [
        self::CALCULATION_PENDING,
        self::CALCULATION_CALCULATED,
        self::CALCULATION_INCOMPLETE,
        self::CALCULATION_FAILED,
    ];

    // --- result_status ------------------------------------------------------
    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';
    public const RESULT_ABSENT = 'absent';
    public const RESULT_WITHHELD = 'withheld';
    public const RESULT_INCOMPLETE = 'incomplete';

    public const RESULT_STATUSES = [
        self::RESULT_PASS,
        self::RESULT_FAIL,
        self::RESULT_ABSENT,
        self::RESULT_WITHHELD,
        self::RESULT_INCOMPLETE,
    ];

    // --- publication_status (derived, never stored) -------------------------
    public const PUBLICATION_UNPUBLISHED = 'unpublished';
    public const PUBLICATION_PUBLISHED = 'published';

    public const PUBLICATION_STATUSES = [
        self::PUBLICATION_UNPUBLISHED,
        self::PUBLICATION_PUBLISHED,
    ];

    protected $fillable = [
        'college_id',
        'examination_id',
        'student_enrollment_id',
        'academic_year_id',
        'academic_term_id',
        'grade_scale_id',
        'total_max_marks',
        'total_obtained_marks',
        'percentage',
        'overall_grade',
        'result_status',
        'calculation_status',
        'calculated_at',
        'calculated_by',
        'published_at',
        'published_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'total_max_marks' => 'decimal:2',
            'total_obtained_marks' => 'decimal:2',
            'percentage' => 'decimal:3',
            'calculated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }

    /**
     * Per-subject outcomes, deterministic order (schedule order, then id) so
     * the same result always renders and paginates identically.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ExamResultItem::class)
            ->orderBy('exam_schedule_id')
            ->orderBy('exam_result_items.id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(ExamResultItem::class);
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
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

    /**
     * Publication is a derived, read-only projection of published_at: there is
     * no separate flag that could drift out of sync with the audit columns.
     */
    public function getPublicationStatusAttribute(): string
    {
        return $this->published_at === null
            ? self::PUBLICATION_UNPUBLISHED
            : self::PUBLICATION_PUBLISHED;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isUnpublished(): bool
    {
        return ! $this->isPublished();
    }

    /**
     * Whether this snapshot has reached the "Ready for Publishing" stage.
     *
     * Calculation is a prerequisite but never a trigger: a calculated result
     * stays unpublished until ResultPublishingService publishes it.
     */
    public function isPublishable(): bool
    {
        $rules = app(ResultRuleService::class);

        return $rules->isPublishableStatus($this->result_status, $this->calculation_status)
            && $rules->hasUsableGradeConfiguration($this);
    }
}
