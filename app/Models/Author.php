<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Author — a reusable, college-owned author record (Library Management, Phase 1).
 *
 * Books reference authors through the `author_book` pivot, so one author can be
 * credited on many books and a book can credit several authors.
 *
 * Duplicate prevention: `name_normalized` is a case-folded, whitespace-collapsed
 * copy of `name` that the model maintains itself; the service and the partial
 * unique index compare on it so "J. K. Rowling" and "j. k.  rowling" are the
 * same author on every database engine.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class Author extends Model
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
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (Author $author): void {
            $author->name_normalized = static::normalizeName((string) $author->name);
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

    /** Books credited to this author, in title-page order. */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'author_book')
            ->withPivot(['college_id', 'sort_order'])
            ->withTimestamps()
            ->orderBy('books.title');
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
