<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Publisher — a reusable, college-owned publisher record (Library Management,
 * Phase 1).
 *
 * A book edition has one publisher, a publisher has many books. The optional
 * contact fields exist for procurement follow-up only; no purchasing or
 * accounting logic lives here.
 *
 * Duplicate prevention mirrors Author: `name_normalized` is maintained by the
 * model and compared by the service and the partial unique index.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class Publisher extends Model
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
        'email',
        'phone',
        'website',
        'address',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (Publisher $publisher): void {
            $publisher->name_normalized = static::normalizeName((string) $publisher->name);
        });
    }

    /** The comparison key used for duplicate detection. */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Book masters published by this publisher. */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
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
