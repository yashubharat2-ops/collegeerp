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

    /** Every status the enquiry form accepts. */
    public const STATUSES = ['new', 'contacted', 'followed_up', 'converted', 'closed', 'dropped'];

    /**
     * Statuses a BULK status change may set. `converted` is deliberately absent:
     * it is a workflow outcome, never a bulk write.
     */
    public const BULK_TARGET_STATUSES = ['contacted', 'followed_up', 'closed', 'dropped'];

    /**
     * Allowed bulk transitions, keyed by the current status. A status with no entry
     * (for example `converted`) cannot be changed in bulk at all.
     */
    public const BULK_TRANSITIONS = [
        'new' => ['contacted', 'followed_up', 'closed', 'dropped'],
        'contacted' => ['followed_up', 'closed', 'dropped'],
        'followed_up' => ['contacted', 'closed', 'dropped'],
        'closed' => ['contacted', 'followed_up'],
        'dropped' => ['contacted', 'followed_up'],
    ];

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
