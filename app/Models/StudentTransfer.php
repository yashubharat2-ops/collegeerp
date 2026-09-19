<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StudentTransfer — a transfer / Transfer Certificate (TC) request.
 *
 * Moving a student out never destroys anything. Approval and issuance only
 * change statuses (student + linked enrollment → `withdrawn`) and record the
 * TC; the Student row and its enrollment/academic/document history stay intact
 * for retention and audit.
 *
 * Two independent status columns:
 * - `status`    request workflow — pending → approved | rejected | cancelled
 * - `tc_status` certificate lifecycle — pending → issued | cancelled
 *
 * `tc_number` is minted server-side by GenerateTcNumber and is unique per
 * college; it is never accepted from the browser.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class StudentTransfer extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    /** Request workflow. */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /** Certificate lifecycle. */
    public const TC_STATUSES = ['pending', 'issued', 'cancelled'];

    protected $fillable = [
        'college_id',
        'student_id',
        'enrollment_id',
        'transfer_date',
        'reason',
        'destination_institution',
        'status',
        'tc_number',
        'tc_issue_date',
        'tc_status',
        'tc_file_path',
        'tc_original_filename',
        'tc_file_size',
        'remarks',
        'requested_by',
        'approved_by',
        'approved_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'tc_issue_date' => 'date',
            'tc_file_size' => 'integer',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'enrollment_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isTcIssued(): bool
    {
        return $this->tc_status === 'issued';
    }

    public function hasTcFile(): bool
    {
        return filled($this->tc_file_path);
    }

    /**
     * Terminal request states: nothing further may be changed or approved.
     */
    public function isClosed(): bool
    {
        return in_array($this->status, ['rejected', 'cancelled'], true);
    }
}
