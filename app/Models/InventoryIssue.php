<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * InventoryIssue — one issue / allocation of consumable stock
 * (Inventory / Asset Management, Phase 3).
 *
 * A row records that `quantity` units of a CONSUMABLE item left the stock
 * and went to a student or a staff member. The stock change itself lives in
 * the Phase 2 ledger: the issue writes a `stock_out` movement through
 * InventoryStockService in the same transaction, and this issue's `number`
 * is stored as that movement's reference, so every issued unit is
 * reconcilable from the transactions screen.
 *
 * Append-only, like the ledger: there is no update or delete path. If stock
 * comes back, it is a new incoming movement — never an edit of this row.
 *
 * `issued_to` is a polymorphic reference to an existing, same-tenant
 * `students` or `faculties` row (staff are the `faculties` table, including
 * the HR Employee alias) so the module never duplicates people.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; the composite
 * `(item_id, college_id)` foreign key enforces the same rule at the database
 * level.
 */
class InventoryIssue extends Model
{
    use BelongsToCollege;

    /** People consumable stock may be issued to. */
    public const RECIPIENT_TYPES = ['student', 'faculty'];

    protected $fillable = [
        'college_id',
        'item_id',
        'number',
        'quantity',
        'issued_to_type',
        'issued_to_id',
        'purpose',
        'reference',
        'movement_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    /** References are stored upper-cased; empty means "not recorded". */
    public function setReferenceAttribute($value): void
    {
        $normalized = strtoupper(trim((string) $value));
        $this->attributes['reference'] = $normalized === '' ? null : $normalized;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo('recipient', 'issued_to_type', 'issued_to_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The person's display name, without touching their own query scopes. */
    public function recipientName(): string
    {
        $recipient = $this->recipient;

        if ($recipient === null) {
            return '—';
        }

        if ($recipient instanceof Student) {
            return trim(implode(' ', array_filter([$recipient->first_name, $recipient->middle_name, $recipient->last_name])));
        }

        if ($recipient instanceof Faculty) {
            return $recipient->full_name;
        }

        return '—';
    }
}
