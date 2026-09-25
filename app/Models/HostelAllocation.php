<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HostelAllocation — allocates an existing StudentEnrollment to an existing
 * HostelBed for an academic year (Hostel Management Phase 2).
 *
 * The allocation is the source of truth for bed occupancy: HostelBed.status
 * is kept synchronized transactionally but never allowed to become an
 * independent conflicting source.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class HostelAllocation extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_VACATED = 'vacated';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_VACATED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'college_id',
        'student_enrollment_id',
        'academic_year_id',
        'hostel_id',
        'hostel_building_id',
        'hostel_room_id',
        'hostel_bed_id',
        'allocation_date',
        'vacated_date',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allocation_date' => 'date',
            'vacated_date' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isVacated(): bool
    {
        return $this->status === self::STATUS_VACATED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'hostel_building_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(HostelBed::class, 'hostel_bed_id');
    }

    /** Fee assignments charged against this allocation. */
    public function feeAssignments(): HasMany
    {
        return $this->hasMany(HostelFeeAssignment::class, 'hostel_allocation_id');
    }

    /** Daily attendance marks recorded against this allocation. */
    public function attendances(): HasMany
    {
        return $this->hasMany(HostelAttendance::class, 'hostel_allocation_id');
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
