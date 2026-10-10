<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AdmissionApplicant — canonical person record for admission.
 *
 * Single source of truth for prospect/applicant personal data, avoiding duplication
 * across enquiries and applications. Reusable by future Student Management (Student
 * will reference applicant_id).
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class AdmissionApplicant extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = [
        'college_id',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'alternate_phone',
        'gender',
        'date_of_birth',
        'address',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(AdmissionEnquiry::class, 'applicant_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'applicant_id');
    }

    /**
     * Why this applicant cannot be deleted yet, or null when it can.
     *
     * Live (not soft-deleted) enquiries, applications and admissions all point at
     * this applicant and keep showing its name. Soft-deleting it would leave them
     * without an applicant, so the delete is refused with a clear reason instead.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->admissions()->exists()) {
            return 'This applicant cannot be deleted because admissions still refer to them. Cancel or resolve those first.';
        }

        if ($this->applications()->exists()) {
            return 'This applicant cannot be deleted because admission applications still refer to them. Delete or resolve those first.';
        }

        if ($this->enquiries()->exists()) {
            return 'This applicant cannot be deleted because admission enquiries still refer to them. Delete or resolve those first.';
        }

        return null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AdmissionDocument::class, 'applicant_id');
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class, 'applicant_id');
    }
}
