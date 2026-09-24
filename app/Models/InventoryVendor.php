<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * InventoryVendor — a college-owned vendor record (Inventory / Asset
 * Management, Phase 1).
 *
 * Contact fields exist so procurement can reach the vendor. No purchase
 * order, receipt or payment logic lives here.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class InventoryVendor extends Model
{
    use BelongsToCollege, SoftDeletes;

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
        'contact_person',
        'phone',
        'email',
        'address',
        'gst_number',
        'status',
        'created_by',
        'updated_by',
    ];

    /** Codes are stored upper-cased and trimmed so "ven-01" and "VEN-01" are the same vendor. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    /** GST numbers are stored without spaces, upper-cased. An empty value is "not recorded". */
    public function setGstNumberAttribute($value): void
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', '', trim((string) $value)));
        $this->attributes['gst_number'] = $normalized === '' ? null : $normalized;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
