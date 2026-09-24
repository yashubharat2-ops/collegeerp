<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Support\DeliveryStates;
use App\Models\CommunicationNotification;
use Tests\TestCase;

/**
 * Communication Management Phase 2 — Delivery / Read Tracking.
 *
 * The tracking state is derived from the EXISTING notification row
 * (sent_at / delivered_at / read_at): Phase 1 read / unread keeps working,
 * no notification is duplicated, and the screen stays tenant-safe and
 * permission-gated.
 */
class CommunicationTrackingTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_the_tracking_screen_is_tenant_scoped(): void
    {
        $college = $this->makeCollege('CTR01');
        $other = $this->makeCollege('CTR01X');
        $user = $this->makeUserWithPermissions($college, ['communication_tracking.view']);

        $this->makeNotification($college, 'user', $user->id, ['title' => 'Our Own Tracking']);
        $foreign = $this->makeNotification($other, 'user', $this->makeMember($other)->id, ['title' => 'Foreign Tracking']);

        $this->asCollege($college, $user)
            ->get(route('communication-tracking.index'))
            ->assertOk()
            ->assertSee('Our Own Tracking')
            ->assertDontSee('Foreign Tracking');

        $this->asCollege($college, $user)
            ->post(route('communication-tracking.delivered', $foreign))
            ->assertNotFound();

        $this->assertNull($foreign->refresh()->delivered_at);
    }

    public function test_the_screen_requires_the_tracking_permission(): void
    {
        $college = $this->makeCollege('CTR02');
        $viewer = $this->makeUserWithPermissions($college, ['communication_tracking.view']);
        $stranger = $this->makeUserWithPermissions($college, ['notifications.view']);

        $this->asCollege($college, $viewer)->get(route('communication-tracking.index'))->assertOk();
        $this->asCollege($college, $stranger)->get(route('communication-tracking.index'))->assertForbidden();
    }

    public function test_sending_stamps_sent_and_reading_stamps_delivered_and_read(): void
    {
        $college = $this->makeCollege('CTR03');
        $manager = $this->makeUserWithPermissions($college, array_merge(self::NOTIFICATION_PERMISSIONS, ['communication_tracking.view']));
        $member = $this->makeMember($college);

        $this->asCollege($college, $manager)
            ->post(route('notifications.store'), $this->notificationPayload('user', $member->id))
            ->assertSessionHasNoErrors();

        $notification = CommunicationNotification::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertNotNull($notification->sent_at, 'A stored notification counts as sent.');
        $this->assertNull($notification->delivered_at);
        $this->assertNull($notification->read_at);
        $this->assertSame(DeliveryStates::SENT, $notification->deliveryState());

        // Phase 1 read/unread keeps working and now implies delivery.
        $this->asCollege($college, $manager)->post(route('notifications.read', $notification))->assertRedirect();
        $notification->refresh();
        $this->assertNotNull($notification->read_at);
        $this->assertNotNull($notification->delivered_at);
        $this->assertSame(DeliveryStates::READ, $notification->deliveryState());

        // Marking unread restores the Phase 1 state without losing delivery.
        $this->asCollege($college, $manager)->post(route('notifications.unread', $notification))->assertRedirect();
        $notification->refresh();
        $this->assertNull($notification->read_at);
        $this->assertNotNull($notification->delivered_at);
        $this->assertSame(DeliveryStates::DELIVERED, $notification->deliveryState());

        // Exactly one notification row exists: tracking duplicates nothing.
        $this->assertSame(1, CommunicationNotification::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_mark_all_read_also_records_delivery(): void
    {
        $college = $this->makeCollege('CTR04');
        $user = $this->makeUserWithPermissions($college, self::NOTIFICATION_PERMISSIONS);

        $this->makeNotification($college, 'user', $user->id, ['title' => 'Bulk one']);
        $this->makeNotification($college, 'user', $user->id, ['title' => 'Bulk two']);

        $this->asCollege($college, $user)->post(route('notifications.read-all'))->assertRedirect();

        $notifications = CommunicationNotification::withoutGlobalScopes()->where('college_id', $college->id)->get();
        $this->assertCount(2, $notifications);
        foreach ($notifications as $notification) {
            $this->assertNotNull($notification->read_at);
            $this->assertNotNull($notification->delivered_at);
        }
    }

    public function test_delivery_can_be_recorded_from_the_tracking_screen_with_the_notification_permission(): void
    {
        $college = $this->makeCollege('CTR05');
        $manager = $this->makeUserWithPermissions($college, ['communication_tracking.view', 'notifications.view', 'notifications.update']);
        $readOnly = $this->makeUserWithPermissions($college, ['communication_tracking.view']);
        $notification = $this->makeNotification($college, 'user', $manager->id, ['title' => 'Delivery target', 'sent_at' => now()]);

        // The read-only tracking viewer cannot change anything.
        $this->asCollege($college, $readOnly)
            ->post(route('communication-tracking.delivered', $notification))
            ->assertForbidden();
        $this->assertNull($notification->refresh()->delivered_at);

        $this->asCollege($college, $manager)
            ->post(route('communication-tracking.delivered', $notification))
            ->assertRedirect(route('communication-tracking.index'));

        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
        $this->assertSame(DeliveryStates::DELIVERED, $notification->deliveryState());

        // Idempotent: a second call keeps the first delivery moment.
        $first = $notification->delivered_at;
        $this->asCollege($college, $manager)->post(route('communication-tracking.delivered', $notification))->assertRedirect();
        $this->assertTrue($first->equalTo($notification->refresh()->delivered_at));
    }

    public function test_the_listing_filters_by_delivery_state(): void
    {
        $college = $this->makeCollege('CTR06');
        $user = $this->makeUserWithPermissions($college, ['communication_tracking.view']);

        $this->makeNotification($college, 'user', $user->id, ['title' => 'Only Sent', 'sent_at' => now()]);
        $this->makeNotification($college, 'user', $user->id, ['title' => 'Was Delivered', 'sent_at' => now(), 'delivered_at' => now()]);
        $this->makeNotification($college, 'user', $user->id, ['title' => 'Already Read', 'sent_at' => now(), 'delivered_at' => now(), 'read_at' => now()]);

        $this->asCollege($college, $user)
            ->get(route('communication-tracking.index', ['state' => DeliveryStates::SENT]))
            ->assertOk()
            ->assertSee('Only Sent')
            ->assertDontSee('Was Delivered')
            ->assertDontSee('Already Read');

        $this->asCollege($college, $user)
            ->get(route('communication-tracking.index', ['state' => DeliveryStates::READ]))
            ->assertOk()
            ->assertSee('Already Read')
            ->assertDontSee('Only Sent');

        // Malformed filters never break the screen.
        $this->asCollege($college, $user)
            ->get(route('communication-tracking.index', ['state' => 'teleported', 'date_from' => 'yesterday']))
            ->assertOk();
    }
}
