<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hostel — the college-owned hostel master (Hostel Management, Phase 1).
 *
 * The top of the hierarchy: College → Hostel → Building → Room → Bed.
 * A hostel holds only descriptive master data; who sleeps where is out of
 * scope for this phase (Hostel Allocation, Phase 2).
 *
 * Identifiers: `code` is stored upper-cased and trimmed, is unique within the
 * college, and stays reserved on archived records. `name` is unique within
 * the college among active (not soft-deleted) hostels.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope; college_id always comes
 * from the authenticated tenant context, never from request data.
 */
class Hostel extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /** Physical hostel categories. Extensible: widening the module is a one-line change here. */
    public const TYPES = ['boys', 'girls', 'mixed'];

    /** Student-eligibility genders. Kept as an editable constant; eligibility is not hard-coded anywhere else. */
    public const GENDERS = ['male', 'female', 'any'];

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'hostel_type',
        'gender',
        'address',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    /** Codes are stored upper-cased and trimmed so "bh-01" and "BH-01" are the same hostel. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Buildings / blocks of this hostel. */
    public function buildings(): HasMany
    {
        return $this->hasMany(HostelBuilding::class, 'hostel_id')->orderBy('name')->orderBy('id');
    }

    /** Rooms across all of the hostel's buildings (denormalized hostel_id). */
    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class, 'hostel_id');
    }

    /** Beds across all of the hostel's rooms (denormalized hostel_id). */
    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class, 'hostel_id');
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
