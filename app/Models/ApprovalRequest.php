<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalRequest extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'college_id',
        'module',
        'action',
        'target_type',
        'target_id',
        'reference_number',
        'title',
        'description',
        'payload',
        'previous_data',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_reason',
        'request_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'previous_data' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function target(): ?Model
    {
        if (! $this->target_type || ! $this->target_id) {
            return null;
        }
        $map = \App\Services\Approval\ApprovalRegistry::modelClassFor($this->target_type);
        if (! $map) {
            return null;
        }
        return $map::withoutGlobalScopes()->where('id', $this->target_id)->first();
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDecided($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }
}
