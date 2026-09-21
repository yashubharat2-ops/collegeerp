<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeeCategory — a college-owned classification of fee heads (Finance / Fees).
 *
 * Categories group fee components for reporting and for the fee structure form
 * (Tuition, Admission, Examination, Library, …). Nothing about a particular
 * institution's fee taxonomy is expressed in code: every college defines its
 * own categories.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class FeeCategory extends Model
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
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Fee structure components classified by this category. */
    public function feeStructureItems(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
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
