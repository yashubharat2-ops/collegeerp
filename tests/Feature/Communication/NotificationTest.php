<?php

namespace Tests\Feature\Communication;

use App\Models\AuditLog;
use App\Models\CommunicationNotification;
use Tests\TestCase;

/**
 * Communication Management — internal (in-app) notifications.
 *
 * Tenant isolation, tenant-safe recipient validation (users / students /
 * staff of the active college only), listing, read / unread behaviour,
 * mark-as-read authorization, filters, deterministic pagination, RBAC,
 * recipient immutability, audit trail and XSS-safe rendering.
 */
class NotificationTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_notifications_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('NTF01');
        $other = $this->makeCollege('NTF01X');
        $manager = $this->makeUserWithPermissions($college, self::NOTIFICATION_PERMISSIONS);
        $foreignUser = $this->makeMember($other, 'Foreign Person');

        $this->makeNotification($college, 'user', $manager->id, ['title' => 'Our notification']);
        $foreign = $this->makeNotification($other, 'user', $foreignUser->id, ['title' => 'Foreign notification']);

        $this->asCollege($college, $manager)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Our notification')
            ->assertDontSee('Foreign notification')
            ->assertDontSee('Foreign Person');

        foreach (['notifications.show', 'notifications.edit'] as $route) {
            $this->asCollege($college, $manager)->get(route($route, $foreign))->assertNotFound();
        }

        $this->asCollege($college, $manager)->put(route('notifications.update', $foreign), ['title' => 'Hijacked', 'message' => 'x', 'notification_type' => 'general', 'priority' => 'normal'])->assertNotFound();
        $this->asCollege($college, $manager)->post(route('notifications.read', $foreign))->assertNotFound();
        $this->asCollege($college, $manager)->post(route('notifications.unread', $foreign))->assertNotFound();
        $this->asCollege($college, $manager)->delete(route('notifications.destroy', $foreign))->assertNotFound();

        $foreign->refresh();
        $this->assertSame('Foreign notification', $foreign->title);
        $this->assertNull($foreign->read_at);

        // A person who belongs to BOTH colleges only sees / marks the active
        // college's notifications addressed to them.
        $shared = $this->makeUserWithPermissions($college, ['notifications.view']);
        $shared->colleges()->attach($other->id, ['is_default' => false]);
        $sharedForeign = $this->makeNotification($other, 'user', $shared->id, ['title' => 'Addressed in the other college']);

        $this->asCollege($college, $shared)
            ->get(route('notifications.index', ['scope' => 'mine']))
            ->assertOk()
            ->assertDontSee('Addressed in the other college');
        $this->asCollege($college, $shared)->post(route('notifications.read', $sharedForeign))->assertNotFound();
        $this->asCollege($college, $shared)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertNull($sharedForeign->refresh()->read_at, 'Mark-all-read never crosses colleges.');
    }

    public function test_recipients_must_belong_to_the_active_college(): void
    {
        $college = $this->makeCollege('NTF02');
        $other = $this->makeCollege('NTF02X');
        $manager = $this->makeUserWithPermissions($college, self::NOTIFICATION_PERMISSIONS);

        $member = $this->makeMember($college, 'Priya Sharma');
        $student = $this->makeStudent($college, 'Asha');
        $staff = $this->makeStaff($college, 'Ravi');

        $outsider = $this->makeMember($other, 'Outside User');
        $foreignStudent = $this->makeStudent($other, 'Foreign');
        $foreignStaff = $this->makeStaff($other, 'Foreign');
        $inactive = $this->makeMember($college, 'Inactive User');
        $inactive->forceFill(['is_active' => false])->save();
        $archivedStudent = $this->makeStudent($college, 'Archived');
        $archivedStudent->delete();

        foreach ([
            ['user', $outsider->id],
            ['student', $foreignStudent->id],
            ['staff', $foreignStaff->id],
            ['user', 999999],
            ['user', $inactive->id],
            ['student', $archivedStudent->id],
        ] as [$type, $id]) {
            $this->asCollege($college, $manager)
                ->post(route('notifications.store'), $this->notificationPayload($type, $id))
                ->assertSessionHasErrors('recipient_id');
        }

        $this->asCollege($college, $manager)
            ->post(route('notifications.store'), $this->notificationPayload('guardian', $member->id))
            ->assertSessionHasErrors('recipient_type');

        $this->assertSame(0, CommunicationNotification::withoutGlobalScopes()->count(), 'Nothing is stored for an invalid recipient.');

        foreach ([['user', $member->id], ['student', $student->id], ['staff', $staff->id]] as [$type, $id]) {
            $this->asCollege($college, $manager)
                ->post(route('notifications.store'), $this->notificationPayload($type, $id, ['college_id' => $other->id, 'created_by' => 9999, 'read_at' => now()->toDateTimeString()]))
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        // The form's combined "type:id" value is accepted as well.
        $this->asCollege($college, $manager)
            ->post(route('notifications.store'), [
                'recipient' => "student:{$student->id}",
                'title' => 'Combined recipient',
                'message' => 'Sent through the form select.',
                'notification_type' => 'general',
                'priority' => 'urgent',
            ])
            ->assertSessionHasNoErrors();

        $stored = CommunicationNotification::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(4, $stored);
        $this->assertTrue($stored->every(fn ($n) => $n->college_id === $college->id), 'college_id always comes from the tenant context.');
        $this->assertTrue($stored->every(fn ($n) => $n->created_by === $manager->id));
        $this->assertTrue($stored->every(fn ($n) => $n->read_at === null), 'New notifications start unread.');
        $this->assertSame(['user', 'student', 'staff', 'student'], $stored->pluck('recipient_type')->all());
        $this->assertSame($student->id, $stored[3]->recipient_id);
    }

    public function test_the_listing_shows_recipients_and_read_state(): void
    {
        $college = $this->makeCollege('NTF03');
        $manager = $this->makeUserWithPermissions($college, self::NOTIFICATION_PERMISSIONS);

        $empty = $this->asCollege($college, $manager)->get(route('notifications.index'))->assertOk();
        $empty->assertSee('No notifications have been sent in this college yet.');

        $member = $this->makeMember($college, 'Priya Sharma');
        $student = $this->makeStudent($college, 'Asha');
        $staff = $this->makeStaff($college, 'Ravi');

        $this->makeNotification($college, 'user', $member->id, ['title' => 'For a user']);
        $this->makeNotification($college, 'student', $student->id, ['title' => 'For a student', 'read_at' => now()]);
        $this->makeNotification($college, 'staff', $staff->id, ['title' => 'For staff', 'priority' => 'urgent']);

        $response = $this->asCollege($college, $manager)->get(route('notifications.index'))->assertOk();

        $response->assertSee('For a user')
            ->assertSee('For a student')
            ->assertSee('For staff')
            ->assertSee('Priya Sharma')
            ->assertSee('Asha Learner ('.$student->student_number.')')
            ->assertSee('Ravi Teacher ('.$staff->employee_code.')')
            ->assertSee('Unread')
            ->assertSee('Read')
            ->assertSee('Urgent');

        $this->assertSame(3, $response->viewData('notifications')->total());

        $this->asCollege($college, $manager)
            ->get(route('notifications.show', CommunicationNotification::withoutGlobalScopes()->where('title', 'For staff')->firstOrFail()))
            ->assertOk()
            ->assertSee('Ravi Teacher');
    }

    public function test_read_and_unread_state_transitions(): void
    {
        $college = $this->makeCollege('NTF04');
        $recipient = $this->makeUserWithPermissions($college, ['notifications.view']);
        $someoneElse = $this->makeMember($college, 'Someone Else');

        $first = $this->makeNotification($college, 'user', $recipient->id, ['title' => 'First']);
        $second = $this->makeNotification($college, 'user', $recipient->id, ['title' => 'Second']);
        $notMine = $this->makeNotification($college, 'user', $someoneElse->id, ['title' => 'Not mine']);

        $this->assertSame(2, $this->asCollege($college, $recipient)->get(route('notifications.index'))->viewData('myUnread'));

        $this->asCollege($college, $recipient)->post(route('notifications.read', $first))->assertRedirect(route('notifications.index'));
        $readAt = $first->refresh()->read_at;
        $this->assertNotNull($readAt);

        // Idempotent: marking read again changes nothing and is not re-audited.
        $this->asCollege($college, $recipient)->post(route('notifications.read', $first), ['return' => 'show'])->assertRedirect(route('notifications.show', $first));
        $this->assertTrue($first->refresh()->read_at->equalTo($readAt));
        $this->assertSame(1, AuditLog::query()->where('action', 'notifications.read')->where('subject_id', $first->id)->count());

        $this->asCollege($college, $recipient)->post(route('notifications.unread', $first))->assertRedirect();
        $this->assertNull($first->refresh()->read_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.unread', 'subject_id' => $first->id, 'user_id' => $recipient->id, 'college_id' => $college->id]);

        $unreadMine = $this->asCollege($college, $recipient)
            ->get(route('notifications.index', ['scope' => 'mine', 'read_status' => 'unread']))
            ->viewData('notifications')->getCollection()->pluck('title')->sort()->values()->all();
        $this->assertSame(['First', 'Second'], $unreadMine);

        $this->asCollege($college, $recipient)
            ->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index', ['scope' => 'mine']))
            ->assertSessionHas('success', '2 notifications marked as read.');

        $this->assertNotNull($first->refresh()->read_at);
        $this->assertNotNull($second->refresh()->read_at);
        $this->assertNull($notMine->refresh()->read_at, 'Mark-all-read only touches the current user\'s notifications.');
        $this->assertSame(0, $this->asCollege($college, $recipient)->get(route('notifications.index'))->viewData('myUnread'));

        $log = AuditLog::query()->where('action', 'notifications.read_all')->firstOrFail();
        $this->assertSame(2, $log->new_values['count']);
        $this->assertSame($recipient->id, $log->user_id);
    }

    public function test_mark_as_read_is_authorized(): void
    {
        $college = $this->makeCollege('NTF05');
        $other = $this->makeCollege('NTF05X');
        $recipient = $this->makeUserWithPermissions($college, ['notifications.view']);
        $bystander = $this->makeUserWithPermissions($college, ['notifications.view']);
        $manager = $this->makeUserWithPermissions($college, ['notifications.view', 'notifications.update']);
        $outsider = $this->makeUserWithPermissions($other, self::NOTIFICATION_PERMISSIONS);
        $noPermission = $this->makeUserWithPermissions($college, ['students.view']);

        $notification = $this->makeNotification($college, 'user', $recipient->id);
        $forNoPermission = $this->makeNotification($college, 'user', $noPermission->id);

        // Not the recipient and no update permission.
        $this->asCollege($college, $bystander)->post(route('notifications.read', $notification))->assertForbidden();
        $this->assertNull($notification->refresh()->read_at);

        // The recipient still needs the module's view permission.
        $this->asCollege($college, $noPermission)->post(route('notifications.read', $forNoPermission))->assertForbidden();
        $this->assertNull($forNoPermission->refresh()->read_at);

        // Another college never reaches the record.
        $this->asCollege($other, $outsider)->post(route('notifications.read', $notification))->assertNotFound();
        $this->assertNull($notification->refresh()->read_at);

        $this->asCollege($college, $recipient)->post(route('notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->refresh()->read_at);

        $this->asCollege($college, $bystander)->post(route('notifications.unread', $notification))->assertForbidden();
        $this->assertNotNull($notification->refresh()->read_at);

        // notifications.update may manage read state for anyone in the college.
        $this->asCollege($college, $manager)->post(route('notifications.unread', $notification))->assertRedirect();
        $this->assertNull($notification->refresh()->read_at);

        // The buttons render only for those allowed to use them.
        $this->asCollege($college, $bystander)->get(route('notifications.index'))->assertOk()->assertDontSee(route('notifications.read', $notification), false);
        $this->asCollege($college, $recipient)->get(route('notifications.index'))->assertOk()->assertSee(route('notifications.read', $notification), false);

        $this->app['auth']->forgetGuards();
        $this->post(route('notifications.read', $notification))->assertRedirect(route('login'));
    }

    public function test_filters_narrow_the_listing(): void
    {
        $college = $this->makeCollege('NTF06');
        $me = $this->makeUserWithPermissions($college, ['notifications.view']);
        $student = $this->makeStudent($college);
        $staff = $this->makeStaff($college);

        $this->makeNotification($college, 'user', $me->id, ['title' => 'Server maintenance', 'priority' => 'urgent', 'notification_type' => 'alert', 'created_at' => '2026-03-10 10:00:00']);
        $this->makeNotification($college, 'student', $student->id, ['title' => 'Library book due', 'priority' => 'normal', 'notification_type' => 'reminder', 'read_at' => now(), 'created_at' => '2026-04-15 10:00:00']);
        $this->makeNotification($college, 'staff', $staff->id, ['title' => 'Submit marks', 'message' => 'Internal assessment marks are due.', 'priority' => 'important', 'notification_type' => 'task', 'created_at' => '2026-05-20 10:00:00']);

        $titles = fn (array $query) => $this->asCollege($college, $me)
            ->get(route('notifications.index', $query))
            ->assertOk()
            ->viewData('notifications')
            ->getCollection()
            ->pluck('title')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Library book due'], $titles(['read_status' => 'read']));
        $this->assertSame(['Server maintenance', 'Submit marks'], $titles(['read_status' => 'unread']));
        $this->assertSame(['Server maintenance'], $titles(['priority' => 'urgent']));
        $this->assertSame(['Submit marks'], $titles(['notification_type' => 'Task']));
        $this->assertSame(['Library book due'], $titles(['recipient_type' => 'student']));
        $this->assertSame(['Server maintenance'], $titles(['scope' => 'mine']));
        $this->assertSame(['Submit marks'], $titles(['search' => 'assessment']));
        $this->assertSame(['Library book due'], $titles(['date_from' => '2026-04-01', 'date_to' => '2026-04-30']));

        $all = ['Library book due', 'Server maintenance', 'Submit marks'];
        $this->assertSame($all, $titles(['read_status' => 'maybe', 'priority' => ['urgent'], 'scope' => 'everyone', 'date_to' => 'yesterday']));
    }

    public function test_pagination_is_deterministic_for_identical_timestamps(): void
    {
        $college = $this->makeCollege('NTF07');
        $user = $this->makeUserWithPermissions($college, ['notifications.view']);
        $at = now()->subHour()->startOfSecond();

        $ids = collect(range(1, 20))
            ->map(fn (int $i) => $this->makeNotification($college, 'user', $user->id, ['title' => "Paged {$i}", 'created_at' => $at, 'updated_at' => $at])->id)
            ->sortDesc()
            ->values();

        $page1 = $this->asCollege($college, $user)->get(route('notifications.index'))->assertOk()->viewData('notifications');
        $page2 = $this->asCollege($college, $user)->get(route('notifications.index', ['page' => 2]))->assertOk()->viewData('notifications');

        $first = $page1->getCollection()->pluck('id')->all();
        $second = $page2->getCollection()->pluck('id')->all();

        $this->assertSame(20, $page1->total());
        $this->assertSame($ids->take(15)->all(), $first);
        $this->assertSame($ids->slice(15)->values()->all(), $second);
        $this->assertSame([], array_intersect($first, $second));
    }

    public function test_crud_requires_permissions_and_the_recipient_is_immutable(): void
    {
        $college = $this->makeCollege('NTF08');
        $manager = $this->makeUserWithPermissions($college, self::NOTIFICATION_PERMISSIONS);
        $viewer = $this->makeUserWithPermissions($college, ['notifications.view']);
        $stranger = $this->makeUserWithPermissions($college, ['notices.view']);
        $member = $this->makeMember($college);
        $otherMember = $this->makeMember($college, 'Other Member');
        $notification = $this->makeNotification($college, 'user', $member->id, ['title' => 'Original title']);

        $this->asCollege($college, $viewer)->get(route('notifications.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('notifications.store'), $this->notificationPayload('user', $member->id))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('notifications.edit', $notification))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('notifications.update', $notification), ['title' => 'x', 'message' => 'x', 'notification_type' => 'general', 'priority' => 'normal'])->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('notifications.destroy', $notification))->assertForbidden();

        $this->asCollege($college, $stranger)->get(route('notifications.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('notifications.show', $notification))->assertForbidden();

        $this->asCollege($college, $manager)->get(route('notifications.create'))->assertOk()->assertSee('Other Member');
        $this->asCollege($college, $manager)->get(route('notifications.edit', $notification))->assertOk();

        $this->asCollege($college, $manager)
            ->put(route('notifications.update', $notification), [
                'title' => 'Corrected title',
                'message' => 'Corrected message.',
                'notification_type' => 'Announcement',
                'priority' => 'important',
                'recipient_type' => 'user',
                'recipient_id' => $otherMember->id,
                'recipient' => "user:{$otherMember->id}",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('notifications.show', $notification));

        $notification->refresh();
        $this->assertSame('Corrected title', $notification->title);
        $this->assertSame('announcement', $notification->notification_type);
        $this->assertSame('important', $notification->priority);
        $this->assertSame($member->id, $notification->recipient_id, 'The recipient never changes after sending.');

        $updated = AuditLog::query()->where('action', 'notifications.updated')->where('subject_id', $notification->id)->firstOrFail();
        $this->assertSame('Original title', $updated->old_values['title']);
        $this->assertSame('Corrected title', $updated->new_values['title']);
        $this->assertArrayNotHasKey('recipient_id', $updated->new_values);

        $this->asCollege($college, $manager)->delete(route('notifications.destroy', $notification))->assertRedirect(route('notifications.index'));
        $this->assertDatabaseMissing('communication_notifications', ['id' => $notification->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'notifications.deleted', 'subject_id' => $notification->id, 'user_id' => $manager->id, 'college_id' => $college->id]);
    }

    public function test_user_content_is_rendered_escaped(): void
    {
        $college = $this->makeCollege('NTF09');
        $manager = $this->makeUserWithPermissions($college, self::NOTIFICATION_PERMISSIONS);
        $title = '<script>alert("n")</script>';
        $message = '<iframe src="javascript:alert(1)"></iframe>';
        $notification = $this->makeNotification($college, 'user', $manager->id, ['title' => $title, 'message' => $message]);

        $this->asCollege($college, $manager)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee($title, false)
            ->assertSee(e($title), false);

        $this->asCollege($college, $manager)
            ->get(route('notifications.show', $notification))
            ->assertOk()
            ->assertDontSee($title, false)
            ->assertDontSee($message, false)
            ->assertSee(e($message), false);
    }
}
