<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * FeeStructureItem — one fee head (category + amount) of a FeeStructure.
 *
 * Items follow their parent structure's lifecycle and are not soft-deleted
 * themselves: the parent owns the history, the child rows are its current
 * configuration.
 *
 * Amounts are money and must never be negative. The rule is enforced in three
 * layers — Form Requests, FeeStructureService, and this model's saving guard
 * (which also protects seeders, factories and tinker) — plus a CHECK constraint
 * on the database engines that support adding one to an existing table.
 */
class FeeStructureItem extends Model
{
    use HasFactory, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /** Largest amount the decimal(12,2) column can hold. */
    public const MAX_AMOUNT = 9999999999.99;

    protected $fillable = [
        'college_id',
        'fee_structure_id',
        'name',
        'amount',
        'description',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->amount === null) {
                return;
            }

            if ((float) $item->amount < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The amount must not be negative.',
                ]);
            }
        });
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
