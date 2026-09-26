<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * InventoryAssignment — one lending of an individual asset
 * (Inventory / Asset Management, Phase 3).
 *
 * Assignment is custody, not consumption: the asset's on-hand quantity is
 * never changed and no stock movement is written. This table IS the custody
 * history of the asset.
 *
 * Lifecycle: a new assignment is written with `status = 'active'`; the
 * Asset Return module flips it to `status = 'returned'` with the return
 * date, actor and notes. The row is never deleted — a later re-assignment
 * of the same asset is a NEW row, so the full history stays in place.
 *
 * At most one row per asset may be active at a time (partial unique index
 * on SQLite/PostgreSQL, row-locked service check elsewhere).
 *
 * `assigned_to` is a polymorphic reference to an existing, same-tenant
 * `students` or `faculties` row (staff are the `faculties` table, including
 * the HR Employee alias) so the module never duplicates people.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; the composite
 * `(item_id, college_id)` foreign key enforces the same rule at the database
 * level.
 */
class InventoryAssignment extends Model
{
    use BelongsToCollege;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RETURNED,
    ];

    /** People an asset may be assigned to. */
    public const ASSIGNEE_TYPES = ['student', 'faculty'];

    protected $fillable = [
        'college_id',
        'item_id',
        'assigned_to_type',
        'assigned_to_id',
        'purpose',
        'assigned_on',
        'returned_on',
        'returned_by',
        'return_notes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'returned_on' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function assignee(): MorphTo
    {
        return $this->morphTo('assignee', 'assigned_to_type', 'assigned_to_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** The person's display name, without touching their own query scopes. */
    public function assigneeName(): string
    {
        $assignee = $this->assignee;

        if ($assignee === null) {
            return '—';
        }

        if ($assignee instanceof Student) {
            return trim(implode(' ', array_filter([$assignee->first_name, $assignee->middle_name, $assignee->last_name])));
        }

        if ($assignee instanceof Faculty) {
            return $assignee->full_name;
        }

        return '—';
    }

    /**
     * @param  Builder<InventoryAssignment>  $query
     * @return Builder<InventoryAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<InventoryAssignment>  $query
     * @return Builder<InventoryAssignment>
     */
    public function scopeReturned(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RETURNED);
    }
}
