<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Student — the officially enrolled person, distinct from AdmissionApplicant.
 *
 * AdmissionApplicant is the single source of truth for the person during the
 * enquiry/application lifecycle; Student is the lifecycle entity that begins
 * at enrollment. Person data is snapshotted onto the Student at conversion time
 * (rather than joined live), so the record remains historically stable even if
 * the applicant is later corrected or soft-deleted. Provenance is preserved via
 * admission_application_id.
 *
 * A Student holds no permanent academic-year/program binding: the recurring,
 * tenant-scoped enrollment history lives in StudentEnrollment.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class Student extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUSES = ['active', 'inactive', 'graduated', 'suspended', 'withdrawn'];

    protected $fillable = [
        'college_id',
        'admission_application_id',
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'alternate_phone',
        'gender',
        'date_of_birth',
        'admission_date',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'photo_path',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Provenance link back to the approved admission application, when the
     * student was created via admission-to-student conversion.
     */
    public function admissionApplication(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id');
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class, 'student_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(StudentPromotion::class, 'student_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StudentTransfer::class, 'student_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Full display name from the snapshot person data.
     */
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]))) ?: $this->student_number;
    }

    /**
     * The student's current (active) enrollment, if any.
     *
     * Deterministic: the oldest-created active enrollment wins, with the id as
     * a tiebreak, so the "current enrollment" never flips between requests.
     */
    public function currentEnrollment(): ?StudentEnrollment
    {
        return $this->enrollments
            ->filter(fn (StudentEnrollment $enrollment) => $enrollment->status === 'active')
            ->sortBy(fn (StudentEnrollment $enrollment) => sprintf(
                '%011d%011d',
                $enrollment->created_at?->timestamp ?? 0,
                $enrollment->id
            ))
            ->first();
    }
}
