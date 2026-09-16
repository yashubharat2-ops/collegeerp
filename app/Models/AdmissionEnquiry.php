<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AdmissionEnquiry — prospective student/contact before formal application.
 *
 * Person data lives in AdmissionApplicant (single source of truth). Enquiry tracks
 * interested program, academic year, source, status, and conversion chain.
 *
 * Tenant isolation via BelongsToCollege.
 */
class AdmissionEnquiry extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = [
        'college_id',
        'applicant_id',
        'academic_year_id',
        'program_id',
        'enquiry_number',
        'source',
        'status',
        'remarks',
        'enquired_at',
        'next_follow_up_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enquired_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplicant::class, 'applicant_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class, 'enquiry_id');
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
