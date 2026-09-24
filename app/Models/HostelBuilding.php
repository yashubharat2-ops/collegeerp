<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelBuilding — a building / block of a hostel (Hostel Management, Phase 1).
 *
 * Every building belongs to exactly one hostel of the same college; the
 * composite foreign key (hostel_id, college_id) → hostels (id, college_id)
 * makes same-tenant ownership a database guarantee.
 *
 * `code` is stored upper-cased and trimmed, is unique within its hostel (and
 * college), and stays reserved on archived records. `floors`, when provided,
 * is the number of floors; rooms can be checked against it.
 *
 * The parent hostel is fixed at creation and cannot be changed afterwards —
 * blocks are never reparented behind the room/bed history's back.
 */
class HostelBuilding extends Model
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
        'hostel_id',
        'name',
        'code',
        'floors',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'floors' => 'integer',
        ];
    }

    /** Codes are stored upper-cased and trimmed. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class, 'hostel_id');
    }

    /** Rooms of this building. */
    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class, 'building_id')->orderBy('room_number')->orderBy('id');
    }

    /** Beds across all of the building's rooms (denormalized building_id). */
    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class, 'building_id');
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
