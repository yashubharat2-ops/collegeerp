<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use App\Domain\Student\Support\RegistrationDraftUpgrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The four-step registration wizard's working copy.
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
     * The four steps, in order. The labels are the UI contract and are defined
     * once, in the wizard's rules class, so the progress indicator, the routes
     * and this model cannot disagree.
     */
    public const STEPS = \App\Domain\Student\Support\RegistrationStepRules::STEPS;

    public const LAST_STEP = 4;

    /**
     * Payload translated to the current wizard shape (see
     * RegistrationDraftUpgrade). Cached per instance, never persisted here — the
     * data migration and the next save converge the stored JSON.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $upgradedPayloadCache = null;

    /** Fingerprint of the raw payload the cache was built from. */
    private ?string $upgradedPayloadSource = null;

    protected $fillable = [
        'college_id', 'user_id', 'status', 'current_step', 'payload',
        'duplicate_warnings', 'student_id', 'approval_request_id', 'submitted_at',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'duplicate_warnings' => 'array',
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

    /**
     * The payload in the CURRENT wizard shape.
     *
     * A draft written by the old five-step wizard is translated here (basic and
     * parent merged into step 1, later steps shifted down) instead of being
     * reinterpreted with the new numbers — see RegistrationDraftUpgrade.
     *
     * @return array<int, array<string, mixed>>
     */
    public function upgradedPayload(): array
    {
        $raw = is_array($this->payload) ? $this->payload : [];
        $source = md5((string) json_encode($raw));

        // The cache is keyed by the raw payload, so an update to the model (or a
        // save in this same request) can never be read through a stale copy.
        if ($this->upgradedPayloadCache === null || $this->upgradedPayloadSource !== $source) {
            $this->upgradedPayloadCache = RegistrationDraftUpgrade::upgradePayload($raw);
            $this->upgradedPayloadSource = $source;
        }

        return $this->upgradedPayloadCache;
    }

    /** True when the stored JSON still uses the old five-step layout. */
    public function hasLegacyPayload(): bool
    {
        return RegistrationDraftUpgrade::isLegacyPayload(is_array($this->payload) ? $this->payload : []);
    }

    /** The step the wizard should reopen, translated from any legacy number. */
    public function currentStep(): int
    {
        return RegistrationDraftUpgrade::resumeStep(
            is_array($this->payload) ? $this->payload : [],
            (int) $this->current_step,
        );
    }

    /** The stored payload of one step (never null). */
    public function stepData(int $step): array
    {
        $payload = $this->upgradedPayload();

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
