<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentTransportAssignment — one StudentEnrollment assigned to an existing
 * transport route + stop for one academic year.
 *
 * Nothing is duplicated: the student, enrollment, academic year, route and stop
 * stay owned by the masters this row references. Composite foreign keys
 * (route, college) and (stop, college) keep the references tenant-safe at the
 * database level; the "stop belongs to the selected route" rule is enforced by
 * the service under a row lock.
 *
 * History is preserved: completed / cancelled rows stay forever, and only ONE
 * active assignment may exist per (enrollment, academic year) — enforced by a
 * partial unique index on SQLite/Postgres and by the service-level lock guard
 * on every engine.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class StudentTransportAssignment extends Model
{
    use SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'college_id',
        'student_enrollment_id',
        'academic_year_id',
        'transport_route_id',
        'transport_stop_id',
        'start_date',
        'end_date',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function transportRoute(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class);
    }

    public function transportStop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class);
    }

    /** Transport fee assignments charged against this transport assignment. */
    public function feeAssignments(): HasMany
    {
        return $this->hasMany(StudentTransportFeeAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Fee assignments still carry a payable balance while not cancelled. */
    public function feePayable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_COMPLETED], true);
    }
}
