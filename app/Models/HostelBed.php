<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelBed — a bed inside a hostel room (Hostel Management, Phase 1).
 *
 * The leaf of the hierarchy. The bed belongs to exactly one room of the same
 * college (composite foreign key); `hostel_id` and `building_id` are
 * denormalized copies stamped by the service from the owning room.
 *
 * Status — IMPORTANT ARCHITECTURE: `available` / `occupied` / `inactive` is a
 * Phase 1 operational flag managed on this master. Future Hostel Allocation
 * (Phase 2) owns occupancy: it connects existing StudentEnrollment records to
 * beds and becomes the source of truth for which bed is occupied. Phase 1
 * deliberately has no student allocation of its own.
 *
 * `bed_number` is unique within its room and reserved on archived records so
 * allocation history can never become ambiguous.
 */
class HostelBed extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_OCCUPIED,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'college_id',
        'hostel_id',
        'building_id',
        'room_id',
        'bed_number',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    /** Bed numbers/labels are stored trimmed. */
    public function setBedNumberAttribute($value): void
    {
        $this->attributes['bed_number'] = trim((string) $value);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    public function isOccupied(): bool
    {
        return $this->status === self::STATUS_OCCUPIED;
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'room_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'building_id');
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class, 'hostel_id');
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
