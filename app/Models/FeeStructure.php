<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeStructure — a college-owned fee plan (Finance / Fees foundation).
 *
 * A structure is always scoped to an academic year and a program, and
 * optionally to one academic term; the individual fee heads and their amounts
 * live in FeeStructureItem rows.
 *
 * Deliberately data-driven: no fee head, amount, slab or academic calendar is
 * expressed in code. Each college configures whatever its fee policy requires.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 *
 * Scope of this foundation: structure definitions only. Fee collection,
 * receipts, discounts, fines, refunds and reports are NOT part of this model.
 */
class FeeStructure extends Model
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
        'academic_year_id',
        'program_id',
        'academic_term_id',
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

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** Nullable: a structure may cover the whole academic year. */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * Fee heads, deterministic order: sort_order first, then id — so the same
     * structure always renders and totals identically.
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class)
            ->where('status', FeeStructureItem::STATUS_ACTIVE);
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

    /** Total of the ACTIVE fee heads — inactive heads are not charged. */
    public function totalAmount(): float
    {
        return (float) $this->activeItems()->sum('amount');
    }
}
