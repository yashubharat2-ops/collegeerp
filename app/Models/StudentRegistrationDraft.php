<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The five-step registration wizard's working copy.
 *
 * A draft is NOT a student: no `students` row exists until the final submit, so
 * an abandoned or half-finished registration can never appear in the student
 * list, the fee ledger or a report. It is owned by one user inside one college.
 */
class StudentRegistrationDraft extends Model
{
    use BelongsToCollege;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';

    /**
     * The five steps, in order. The labels are the UI contract and are defined
     * once, in the wizard's rules class, so the progress indicator, the routes
     * and this model cannot disagree.
     */
    public const STEPS = \App\Domain\Student\Support\RegistrationStepRules::STEPS;

    public const LAST_STEP = 5;

    protected $fillable = [
        'college_id', 'user_id', 'status', 'current_step', 'payload',
        'student_id', 'approval_request_id', 'submitted_at',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentRegistrationDraftDocument::class, 'draft_id');
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /** The stored payload of one step (never null). */
    public function stepData(int $step): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return is_array($payload[$step] ?? null) ? $payload[$step] : [];
    }

    /** The whole draft flattened for validation and pre-filling. */
    public function mergedData(): array
    {
        $merged = [];

        for ($step = 1; $step <= self::LAST_STEP; $step++) {
            $merged = array_merge($merged, $this->stepData($step));
        }

        return $merged;
    }
}
