<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * LibraryMember — a college's library membership of an existing student
 * enrollment (Library Management, Phase 2).
 *
 * The person is the ERP Student reached through StudentEnrollment. This model
 * does not store a name, email or student number; those stay on the student
 * record so there is no second identity system.
 *
 * Tenant isolation: BelongsToCollege + CollegeScope.
 */
class LibraryMember extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'college_id',
        'student_enrollment_id',
        'member_code',
        'membership_date',
        'expiry_date',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'membership_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function setMemberCodeAttribute($value): void
    {
        $this->attributes['member_code'] = strtoupper(trim((string) $value));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** True when an expiry date is set and is before today. Today is still current. */
    public function isPastExpiry(): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->copy()->startOfDay()->lt(now()->startOfDay());
    }

    /** A member may borrow only while the membership itself is active and unexpired. */
    public function canBorrow(): bool
    {
        return $this->isActive() && ! $this->isPastExpiry();
    }

    public function studentEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LibraryTransaction::class)->orderByDesc('issued_on')->orderByDesc('id');
    }

    public function studentName(): string
    {
        return $this->studentEnrollment?->student?->fullName()
            ?: ($this->studentEnrollment?->enrollment_number ?? 'Member');
    }

    public function label(): string
    {
        $enrollment = $this->studentEnrollment?->enrollment_number;

        return $enrollment
            ? $this->member_code.' · '.$this->studentName().' · '.$enrollment
            : $this->member_code.' · '.$this->studentName();
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
