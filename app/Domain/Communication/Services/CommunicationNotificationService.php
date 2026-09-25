<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationPriority;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\CommunicationNotification;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CommunicationNotificationService — internal (in-app) notifications of
 * Communication Management, Phase 1.
 *
 * In-app only: nothing here talks to SMS / e-mail / WhatsApp / push
 * providers or any external API.
 *
 * Guarantees:
 *   - the recipient must be an EXISTING user (active member), student or
 *     staff member of the SAME college — checked here even though the Form
 *     Request validates, so other modules can call send() safely;
 *   - college_id is always the given (active) college; created_by is the
 *     acting user, or null for system notifications;
 *   - the recipient of a notification is immutable after it is sent;
 *   - read / unread transitions are idempotent and audited only when the
 *     state actually changes;
 *   - every mutation is audited.
 */
class CommunicationNotificationService
{
    public const AUDITED = ['recipient_type', 'recipient_id', 'title', 'message', 'notification_type', 'priority', 'read_at'];

    /** Phase 2 delivery / read tracking columns of the same row. */
    public const TRACKED = ['sent_at', 'delivered_at', 'read_at'];

    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * Send one internal notification.
     *
     * @param  array<string, mixed>  $data  recipient_type, recipient_id, title, message, notification_type?, priority?
     */
    public function send(College $college, array $data, ?User $actor = null): CommunicationNotification
    {
        $collegeId = (int) $college->getKey();
        $active = app(TenantContext::class)->id();

        // Never write into a college other than the active tenant.
        abort_if($active !== null && (int) $active !== $collegeId, 403);

        $type = (string) ($data['recipient_type'] ?? '');
        $recipientId = (int) ($data['recipient_id'] ?? 0);

        if (! NotificationRecipients::isValidType($type)) {
            throw ValidationException::withMessages(['recipient_type' => 'The selected recipient type is invalid.']);
        }

        if (! NotificationRecipients::exists($type, $recipientId, $collegeId)) {
            throw ValidationException::withMessages(['recipient_id' => 'The selected recipient does not belong to the active college.']);
        }

        return DB::transaction(function () use ($collegeId, $type, $recipientId, $data, $actor): CommunicationNotification {
            $notification = new CommunicationNotification;
            $notification->college_id = $collegeId;
            $notification->recipient_type = $type;
            $notification->recipient_id = $recipientId;
            $notification->read_at = null;
            // Phase 2 tracking: an internal notification is "sent" the moment
            // it is stored (same row — no duplicated record).
            $notification->sent_at = now();
            $notification->delivered_at = null;
            $notification->created_by = $actor?->getKey();
            $this->fillContent($notification, array_merge([
                'notification_type' => 'general',
                'priority' => CommunicationPriority::NORMAL,
            ], $data));

            $notification->save();

            $this->audit->record('notifications.created', $notification, [], $notification->only(self::AUDITED));

            return $notification->refresh();
        });
    }

    /**
     * Edit the content of a notification. The recipient never changes.
     *
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(CommunicationNotification $notification, array $data, User $actor): CommunicationNotification
    {
        $this->assertTenant($notification);

        return DB::transaction(function () use ($notification, $data): CommunicationNotification {
            $fresh = $this->lockRow($notification);
            $before = $fresh->only(self::AUDITED);

            $this->fillContent($fresh, $data);
            $fresh->save();

            // Audit only the content fields that actually changed.
            $changed = array_values(array_filter(
                ['title', 'message', 'notification_type', 'priority'],
                fn (string $field) => $before[$field] !== $fresh->{$field},
            ));
            $this->audit->record('notifications.updated', $fresh, Arr::only($before, $changed), $fresh->only($changed));

            return $fresh->refresh();
        });
    }

    public function delete(CommunicationNotification $notification, User $actor): void
    {
        $this->assertTenant($notification);

        DB::transaction(function () use ($notification): void {
            $fresh = $this->lockRow($notification);
            $snapshot = $fresh->only(self::AUDITED);

            $fresh->delete();

            $this->audit->record('notifications.deleted', $fresh, $snapshot, []);
        });
    }

    public function markRead(CommunicationNotification $notification, User $actor): CommunicationNotification
    {
        return $this->setReadState($notification, true);
    }

    public function markUnread(CommunicationNotification $notification, User $actor): CommunicationNotification
    {
        return $this->setReadState($notification, false);
    }

    /**
     * Mark every unread notification addressed to $user in the college read.
     *
     * @return int Number of notifications marked read.
     */
    public function markAllReadFor(User $user, College $college): int
    {
        $collegeId = (int) $college->getKey();
        $active = app(TenantContext::class)->id();
        abort_unless($active !== null && (int) $active === $collegeId, 403);

        return DB::transaction(function () use ($user, $collegeId): int {
            $now = now();

            // Phase 2 tracking: stamp delivery before the read state so an
            // unread → read jump never loses the delivered moment.
            CommunicationNotification::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $collegeId)
                ->forUser($user)
                ->unread()
                ->whereNull('delivered_at')
                ->update(['delivered_at' => $now]);

            $count = CommunicationNotification::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $collegeId)
                ->forUser($user)
                ->unread()
                ->update(['read_at' => $now]);

            if ($count > 0) {
                $this->audit->record('notifications.read_all', null, [], [
                    'recipient_type' => NotificationRecipients::USER,
                    'recipient_id' => $user->getKey(),
                    'count' => $count,
                ]);
            }

            return $count;
        });
    }

    private function setReadState(CommunicationNotification $notification, bool $read): CommunicationNotification
    {
        $this->assertTenant($notification);

        return DB::transaction(function () use ($notification, $read): CommunicationNotification {
            $fresh = $this->lockRow($notification);

            if ($fresh->isRead() === $read) {
                return $fresh; // idempotent: nothing to change, nothing to audit
            }

            $old = ['read_at' => $fresh->read_at, 'delivered_at' => $fresh->delivered_at];
            $now = now();
            $fresh->read_at = $read ? $now : null;

            // Phase 2 tracking: reading implies delivery (never un-stamped on
            // "mark unread", so delivery history stays truthful).
            if ($read) {
                $fresh->sent_at ??= $fresh->created_at ?? $now;
                $fresh->delivered_at ??= $now;
            }

            $fresh->save();

            $this->audit->record($read ? 'notifications.read' : 'notifications.unread', $fresh, $old, [
                'read_at' => $fresh->read_at,
                'delivered_at' => $fresh->delivered_at,
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillContent(CommunicationNotification $notification, array $data): void
    {
        if (array_key_exists('title', $data)) {
            $notification->title = trim((string) $data['title']);
        }

        if (array_key_exists('message', $data)) {
            $notification->message = rtrim((string) $data['message']);
        }

        if (array_key_exists('notification_type', $data)) {
            $notification->notification_type = (string) CommunicationTypes::normalize((string) $data['notification_type']);
        }

        if (array_key_exists('priority', $data)) {
            $notification->priority = (string) $data['priority'];
        }

        $errors = [];

        if (trim((string) $notification->title) === '') {
            $errors['title'] = 'A notification title is required.';
        }

        if (trim((string) $notification->message) === '') {
            $errors['message'] = 'A notification message is required.';
        }

        if (! preg_match(CommunicationTypes::PATTERN, (string) $notification->notification_type)) {
            $errors['notification_type'] = 'The notification type may only contain letters, numbers and separators.';
        }

        if (! in_array($notification->priority, CommunicationPriority::ALL, true)) {
            $errors['priority'] = 'The selected priority is invalid.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function lockRow(CommunicationNotification $notification): CommunicationNotification
    {
        return CommunicationNotification::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $notification->college_id)
            ->whereKey($notification->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTenant(CommunicationNotification $notification): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $active === (int) $notification->college_id, 403);
    }
}
