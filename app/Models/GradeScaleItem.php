<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GradeScaleItem — one percentage band of a GradeScale.
 *
 * Items follow their parent scale's lifecycle and are not soft-deleted
 * themselves; the application-level + database-level guard is "no duplicate
 * active grade definition inside one scale".
 */
class GradeScaleItem extends Model
{
    use HasFactory, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'college_id',
        'grade_scale_id',
        'grade',
        'min_percentage',
        'max_percentage',
        'grade_point',
        'description',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'min_percentage' => 'decimal:3',
            'max_percentage' => 'decimal:3',
            'grade_point' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the given percentage falls inside this band.
     *
     * Bands are inclusive on both ends so that 0 and 100 are reachable, and so
     * a college can express "80 and above" as 80–100 without a gap.
     */
    public function covers(float $percentage): bool
    {
        return $percentage >= (float) $this->min_percentage
            && $percentage <= (float) $this->max_percentage;
    }
}
