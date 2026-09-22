<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LibraryTransaction — one issue of a book copy to a library member
 * (Library Management, Phase 2).
 *
 * Status moves issued → returned, or issued → lost. The row is never deleted
 * and issued_on / issued_by are never rewritten. Renewals append
 * LibraryRenewal rows and advance due_on; they do not replace this record.
 *
 * No soft deletes: circulation history is permanent.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope.
 */
class LibraryTransaction extends Model
{
    use HasFactory, BelongsToCollege;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_RETURNED,
        self::STATUS_LOST,
    ];

    protected $fillable = [
        'college_id',
        'book_copy_id',
        'library_member_id',
        'issued_on',
        'due_on',
        'returned_on',
        'status',
        'issued_by',
        'returned_by',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'returned_on' => 'date',
        ];
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isOverdue(): bool
    {
        return $this->isIssued()
            && $this->due_on !== null
            && $this->due_on->copy()->startOfDay()->lt(now()->startOfDay());
    }

    public function bookCopy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class);
    }

    public function libraryMember(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class);
    }

    /** Renewal history in the order it happened. */
    public function renewals(): HasMany
    {
        return $this->hasMany(LibraryRenewal::class, 'issue_transaction_id')->orderBy('id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
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
