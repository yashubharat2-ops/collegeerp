<?php

namespace App\Models;

use App\Domain\Communication\Support\CommunicationPriority;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\DeliveryStates;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal ERP notification (Communication Management, Phase 1).
 *
 * Stored in `communication_notifications` — deliberately NOT Laravel's
 * framework `notifications` table, which already exists for the database
 * notification channel of User (Notifiable) and has a different shape
 * (uuid ids, JSON payload, no tenant column).
 *
 * In-app only: no SMS, e-mail, WhatsApp, push or external API delivery.
 *
 *  - `recipient_type` + `recipient_id` reference an EXISTING user, student
 *    or staff (Faculty) record of the same college — see
 *    NotificationRecipients; no person data is copied;
 *  - `read_at` is null while unread;
 *  - `created_by` is null for system-generated notifications.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope: a notification of
 * another college can never be listed, opened or marked read.
 */
class CommunicationNotification extends Model
{
    use BelongsToCollege;

    protected $table = 'communication_notifications';

    public const PRIORITIES = CommunicationPriority::ALL;

    public const RECIPIENT_TYPES = NotificationRecipients::TYPES;

    /** Suggested (not enforced) notification categories. */
    public const TYPES = CommunicationTypes::NOTIFICATION_TYPES;

    protected $fillable = [
        'college_id',
        'recipient_type',
        'recipient_id',
        'title',
        'message',
        'notification_type',
        'priority',
        'sent_at',
        'delivered_at',
        'read_at',
        'created_by',
    ];

    protected $attributes = [
        'notification_type' => 'general',
        'priority' => CommunicationPriority::NORMAL,
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Phase 2 delivery / read tracking — derived from the timestamps of THIS
     * row (no tracking table, no duplicated record).
     */
    public function deliveryState(): string
    {
        return DeliveryStates::resolve($this->sent_at, $this->delivered_at, $this->read_at);
    }

    public function deliveryStateLabel(): string
    {
        return DeliveryStates::label($this->deliveryState());
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null || $this->read_at !== null;
    }

    /** Whether the notification is addressed to this platform user. */
    public function isAddressedTo(?User $user): bool
    {
        return $user !== null
            && $this->recipient_type === NotificationRecipients::USER
            && (int) $this->recipient_id === (int) $user->getKey();
    }

    public function typeLabel(): string
    {
        return CommunicationTypes::label($this->notification_type, self::TYPES);
    }

    public function recipientTypeLabel(): string
    {
        return self::RECIPIENT_TYPES[$this->recipient_type] ?? ucfirst((string) $this->recipient_type);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('read_at'));
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('read_at'));
    }

    /** Notifications in a given Phase 2 delivery state. */
    public function scopeDeliveryState(Builder $query, string $state): Builder
    {
        return match ($state) {
            DeliveryStates::READ => $query->whereNotNull($this->qualifyColumn('read_at')),
            DeliveryStates::DELIVERED => $query
                ->whereNotNull($this->qualifyColumn('delivered_at'))
                ->whereNull($this->qualifyColumn('read_at')),
            DeliveryStates::SENT => $query
                ->whereNotNull($this->qualifyColumn('sent_at'))
                ->whereNull($this->qualifyColumn('delivered_at'))
                ->whereNull($this->qualifyColumn('read_at')),
            DeliveryStates::PENDING => $query
                ->whereNull($this->qualifyColumn('sent_at'))
                ->whereNull($this->qualifyColumn('delivered_at'))
                ->whereNull($this->qualifyColumn('read_at')),
            default => $query,
        };
    }

    /** Notifications addressed to a platform user (within the active college scope). */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query
            ->where($this->qualifyColumn('recipient_type'), NotificationRecipients::USER)
            ->where($this->qualifyColumn('recipient_id'), $user->getKey());
    }
}
