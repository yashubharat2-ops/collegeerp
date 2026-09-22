<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BookCopy — one physical item of a Book (Library Management, Phase 2).
 *
 * Bibliographic data stays on Book. This row is the accessioned copy: where it
 * sits, what condition it is in, and whether it can be issued. Status `issued`
 * is owned by circulation (LibraryTransactionService); it is not a value the
 * copy form may set.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope. college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class BookCopy extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_LOST = 'lost';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ISSUED,
        self::STATUS_LOST,
        self::STATUS_DAMAGED,
        self::STATUS_WITHDRAWN,
    ];

    /** Statuses a librarian may set on the copy form. `issued` is circulation-owned. */
    public const MANUAL_STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_LOST,
        self::STATUS_DAMAGED,
        self::STATUS_WITHDRAWN,
    ];

    public const CONDITION_NEW = 'new';

    public const CONDITION_GOOD = 'good';

    public const CONDITION_FAIR = 'fair';

    public const CONDITION_POOR = 'poor';

    public const CONDITIONS = [
        self::CONDITION_NEW,
        self::CONDITION_GOOD,
        self::CONDITION_FAIR,
        self::CONDITION_POOR,
    ];

    protected $fillable = [
        'college_id',
        'book_id',
        'accession_number',
        'barcode',
        'copy_number',
        'location',
        'condition',
        'status',
        'acquired_on',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'copy_number' => 'integer',
            'acquired_on' => 'date',
        ];
    }

    public function setAccessionNumberAttribute($value): void
    {
        $this->attributes['accession_number'] = strtoupper(trim((string) $value));
    }

    public function setBarcodeAttribute($value): void
    {
        $normalized = strtoupper(trim((string) $value));
        $this->attributes['barcode'] = $normalized === '' ? null : $normalized;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LibraryTransaction::class)->orderByDesc('issued_on')->orderByDesc('id');
    }

    public function label(): string
    {
        $title = $this->book?->title ?? 'Book';

        return $this->accession_number.' · '.$title.' (copy '.$this->copy_number.')';
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
