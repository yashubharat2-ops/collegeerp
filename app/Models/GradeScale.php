<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GradeScale — a college-owned, configurable grading / pass-fail rule set
 * (Examinations Phase 3).
 *
 * Deliberately data-driven: grade letters, percentage boundaries and grade
 * points all live in GradeScaleItem rows. Nothing about a specific
 * institution's grading system (A/B/C/D, 33%/40%/50%, divisions, …) is
 * expressed in code — the result calculation engine only reads whatever the
 * active college configured here.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class GradeScale extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Grade bands, deterministic order: sort_order first, then the lower
     * boundary, then id — so the same scale always renders and resolves
     * identically.
     */
    public function items(): HasMany
    {
        return $this->hasMany(GradeScaleItem::class)
            ->orderBy('sort_order')
            ->orderBy('min_percentage')
            ->orderBy('id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(GradeScaleItem::class);
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
