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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
