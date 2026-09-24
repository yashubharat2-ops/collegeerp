<?php

namespace App\Models;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS / e-mail communication log (Communication Management, Phase 2).
 *
 * One immutable row per attempted message. The UI is read-only: no create,
 * edit or delete route is registered — rows are written by
 * CommunicationLogService (and, later, by a gateway integration). Phase 2
 * contacts NO external provider; `provider_reference` is just a recorded
 * identifier.
 *
 * `recipient` is the address the message went to; the optional
 * recipient_type + recipient_id REFERENCE an existing user / student / staff
 * record of the same college instead of copying person data.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class CommunicationLog extends Model
{
    use BelongsToCollege;

    public const STATUSES = CommunicationLogStatus::ALL;

    public const CHANNELS = CommunicationChannels::LABELS;

    protected $fillable = [
        'college_id',
        'channel',
        'recipient',
        'recipient_type',
        'recipient_id',
        'communication_template_id',
        'subject',
        'content',
        'status',
        'provider_reference',
        'sent_at',
        'delivered_at',
        'failure_reason',
        'created_by',
    ];

    protected $attributes = [
        'status' => CommunicationLogStatus::QUEUED,
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'communication_template_id' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'communication_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function channelLabel(): string
    {
        return CommunicationChannels::label($this->channel);
    }

    public function statusLabel(): string
    {
        return CommunicationLogStatus::label($this->status);
    }

    public function isFailed(): bool
    {
        return $this->status === CommunicationLogStatus::FAILED;
    }

    public function isTerminal(): bool
    {
        return CommunicationLogStatus::TRANSITIONS[$this->status] === [];
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where($this->qualifyColumn('channel'), $channel);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where($this->qualifyColumn('status'), $status);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), CommunicationLogStatus::FAILED);
    }
}
