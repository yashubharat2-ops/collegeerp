<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BookCategory — a college-owned classification of the book catalogue
 * (Library Management, Phase 1).
 *
 * Categories are pure classification (Reference, Textbook, Journal, …): they
 * hold no stock and no circulation policy. Every college defines its own
 * taxonomy; nothing is hard-coded.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class BookCategory extends Model
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

    /** Codes are stored upper-cased and trimmed so "ref" and "REF" are the same code. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Book masters classified by this category. */
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
