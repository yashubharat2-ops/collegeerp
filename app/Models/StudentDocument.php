<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentDocument — a file belonging to a Student across the whole lifecycle.
 *
 * Distinct from AdmissionDocument (which belongs to an applicant/application
 * during the admission stage): a student accumulates documents long after
 * admission closes, and they must remain accessible from the student's 360°
 * profile.
 *
 * Document TYPE master data is reused from the Admissions module
 * (AdmissionDocumentType) rather than duplicated, so extension/MIME/size
 * configuration lives in exactly one place per college.
 *
 * Security: `file_path` is server-generated (uuid + sanitised extension) under
 * a tenant-scoped private-disk directory and is never accepted from a request.
 * Access is always through an authorized controller action that re-resolves
 * the row through CollegeScope.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class StudentDocument extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const VERIFICATION_STATUSES = ['pending', 'verified', 'rejected'];

    protected $fillable = [
        'college_id',
        'student_id',
        'document_type_id',
        'title',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'issue_date',
        'expiry_date',
        'verification_status',
        'verified_by',
        'verified_at',
        'rejection_remarks',
        'uploaded_by',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(AdmissionDocumentType::class, 'document_type_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPending(): bool
    {
        return $this->verification_status === 'pending';
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function isRejected(): bool
    {
        return $this->verification_status === 'rejected';
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function sizeInKb(): float
    {
        return round(((int) $this->file_size) / 1024, 1);
    }
}
