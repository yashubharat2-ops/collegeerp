<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelRoom — a room inside a building / block (Hostel Management, Phase 1).
 *
 * The room belongs to exactly one building of the same college (composite
 * foreign key). `hostel_id` is a denormalized copy of the building's hostel,
 * stamped by the service — never trusted from request input — so the full
 * hierarchy can be filtered without joins.
 *
 * `room_number` is unique within its building and reserved on archived
 * records. `capacity` is the maximum number of beds the room may hold; the
 * service keeps the active bed count within that ceiling.
 *
 * The parent building is fixed at creation; rooms are never reparented.
 */
class HostelRoom extends Model
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
        'building_id',
        'room_number',
        'floor',
        'room_type',
        'capacity',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /** Room numbers are stored trimmed. */
    public function setRoomNumberAttribute($value): void
    {
        $this->attributes['room_number'] = trim((string) $value);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'building_id');
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class, 'hostel_id');
    }

    /** Beds of this room. */
    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class, 'room_id')->orderBy('bed_number')->orderBy('id');
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
