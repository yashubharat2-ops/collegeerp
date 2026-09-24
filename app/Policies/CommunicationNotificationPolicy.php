<?php

namespace App\Policies;

use App\Models\CommunicationNotification;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Internal notifications authorization (Communication Management).
 *
 * - `notifications.view` opens the college's notification log;
 * - `notifications.create` / `.update` / `.delete` manage notifications;
 * - read / unread may be toggled by the RECIPIENT user themself (with
 *   `notifications.view`) or by anyone holding `notifications.update`.
 *
 * Every record ability requires the notification to belong to the ACTIVE
 * college, so nobody — whatever they hold elsewhere — can read, modify or
 * mark another college's notification.
 */
class CommunicationNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('notifications.view');
    }

    public function view(User $user, CommunicationNotification $notification): bool
    {
        return $this->allowed($user, $notification, 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('notifications.create');
    }

    public function update(User $user, CommunicationNotification $notification): bool
    {
        return $this->allowed($user, $notification, 'update');
    }

    public function delete(User $user, CommunicationNotification $notification): bool
    {
        return $this->allowed($user, $notification, 'delete');
    }

    /** Mark read / unread. */
    public function markRead(User $user, CommunicationNotification $notification): bool
    {
        if (! $this->inActiveCollege($notification)) {
            return false;
        }

        $collegeId = (int) $notification->college_id;

        return $user->hasPermission('notifications.update', $collegeId)
            || ($notification->isAddressedTo($user) && $user->hasPermission('notifications.view', $collegeId));
    }

    /**
     * Record a delivery (Phase 2 tracking). Purely additive: it reuses the
     * existing `notifications.update` permission and never affects the
     * Phase 1 read / unread abilities above.
     */
    public function markDelivered(User $user, CommunicationNotification $notification): bool
    {
        return $this->allowed($user, $notification, 'update');
    }

    private function allowed(User $user, CommunicationNotification $notification, string $action): bool
    {
        return $this->inActiveCollege($notification)
            && $user->hasPermission('notifications.'.$action, (int) $notification->college_id);
    }

    private function inActiveCollege(CommunicationNotification $notification): bool
    {
        $active = app(TenantContext::class)->id();

        return $active !== null && (int) $notification->college_id === (int) $active;
    }
}
