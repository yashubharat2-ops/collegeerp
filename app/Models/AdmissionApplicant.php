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
