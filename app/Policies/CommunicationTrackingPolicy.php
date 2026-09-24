<?php

namespace App\Policies;

use App\Models\User;

/**
 * Delivery / Read Tracking authorization (Communication Management, Phase 2).
 *
 * The screen aggregates existing notification and log records and owns no
 * resource of its own, so the policy is a single screen-level permission:
 * `communication_tracking.view`. Changing a tracking state still goes through
 * the notification's own abilities (CommunicationNotificationPolicy), so this
 * screen can never widen what a user may modify.
 */
class CommunicationTrackingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('communication_tracking.view');
    }
}
