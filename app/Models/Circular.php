<?php

namespace App\Models;

use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Domain\Communication\Traits\HasPublicationWorkflow;
use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Circular (Communication Management, Phase 1).
 *
 * A formal, numbered institutional circular — a separate module from Notices
 * (its own table, workflow permissions and numbering), not an alias.
 *
 *  - `circular_number` is stored trimmed and upper-cased and is unique within
 *    the college, including archived (soft-deleted) circulars: a number, once
 *    issued, is never reused;
 *  - `issue_date` is the formal date printed on the circular; `publish_at`
 *    is optional and is stamped with the publication moment when a circular
 *    is published without a schedule;
 *  - `target_type` is an extensible audience label (CommunicationTargets);
 *  - `status` only moves through the publish / unpublish / archive actions
 *    guarded by `circulars.publish`.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class Circular extends Model
{
    use BelongsToCollege, HasPublicationWorkflow, SoftDeletes;

    public const STATUSES = PublicationWorkflow::STATUSES;

    protected $fillable = [
        'college_id',
        'circular_number',
        'title',
        'subject',
        'content',
        'issue_date',
        'publish_at',
        'expires_at',
        'status',
        'target_type',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => PublicationWorkflow::DRAFT,
        'target_type' => CommunicationTargets::ALL,
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }

    /** Numbers are compared case-insensitively: "cir/01" and "CIR/01" are the same circular. */
    public function setCircularNumberAttribute($value): void
    {
        $this->attributes['circular_number'] = strtoupper(trim((string) $value));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function targetLabel(): string
    {
        return CommunicationTargets::label($this->target_type);
    }
}
