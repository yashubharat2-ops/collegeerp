<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use App\Domain\Library\Support\Isbn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Book — the college-owned bibliographic master (Library Management, Phase 1).
 *
 * A Book describes a title: what the work is, who wrote and published it, and
 * how the library classifies it. Physical items are BookCopy rows that
 * reference this model; nothing on a Book itself is issued, returned or renewed.
 *
 * Identifiers: `code` is the library's own catalogue code (required, unique
 * among the college's active books); `isbn` is optional and, when present,
 * stored normalized (digits and a trailing X) and unique among the college's
 * active books.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class Book extends Model
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
        'book_category_id',
        'publisher_id',
        'title',
        'code',
        'isbn',
        'edition',
        'publication_year',
        'language',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'publication_year' => 'integer',
        ];
    }

    /** Codes are stored upper-cased and trimmed so "bk-001" and "BK-001" are the same code. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    /** ISBNs are stored without hyphens/spaces; an empty value is "no ISBN", not "". */
    public function setIsbnAttribute($value): void
    {
        $this->attributes['isbn'] = Isbn::normalize($value);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BookCategory::class, 'book_category_id');
    }

    /** Nullable: local publications and theses may have no publisher on record. */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /** Credited authors in title-page order (`sort_order`, then id). */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'author_book')
            ->withPivot(['college_id', 'sort_order'])
            ->withTimestamps()
            ->orderBy('author_book.sort_order')
            ->orderBy('author_book.id');
    }

    /** Physical copies of this title. Circulation hangs off the copy, not the book. */
    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class)->orderBy('copy_number')->orderBy('id');
    }

    /** "Author A, Author B" for lists and the dashboard. */
    public function authorNames(): string
    {
        return $this->authors->pluck('name')->implode(', ');
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
