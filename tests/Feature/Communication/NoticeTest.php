<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Services\CommunicationAttachmentService;
use App\Models\AuditLog;
use App\Models\Notice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Communication Management — Notices / Announcements.
 *
 * Tenant isolation, RBAC, CRUD with server-controlled fields, the
 * draft / published / archived workflow, validation, deterministic
 * pagination, filters, soft deletes, secure attachments (authorization and
 * path-traversal defences), audit trail and XSS-safe rendering.
 */
class NoticeTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_notices_are_isolated_per_college(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('NTC01');
        $other = $this->makeCollege('NTC01X');
        $user = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        $this->makeNotice($college, ['title' => 'Our Own Notice']);
        $foreignPath = "communication/notices/{$other->id}/foreign.pdf";
        Storage::disk('private')->put($foreignPath, 'foreign');
        $foreign = $this->makeNotice($other, [
            'title' => 'Foreign Notice',
            'attachment_path' => $foreignPath,
            'attachment_name' => 'foreign.pdf',
        ]);

        $this->asCollege($college, $user)
            ->get(route('notices.index'))
            ->assertOk()
            ->assertSee('Our Own Notice')
            ->assertDontSee('Foreign Notice');

        foreach (['notices.show', 'notices.edit', 'notices.attachment'] as $route) {
            $this->asCollege($college, $user)->get(route($route, $foreign))->assertNotFound();
        }

        $this->asCollege($college, $user)->put(route('notices.update', $foreign), $this->noticePayload(['title' => 'Hijacked']))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('notices.destroy', $foreign))->assertNotFound();

        foreach (['notices.publish', 'notices.unpublish', 'notices.archive'] as $route) {
            $this->asCollege($college, $user)->post(route($route, $foreign))->assertNotFound();
        }

        $foreign->refresh();
        $this->assertSame('Foreign Notice', $foreign->title);
        $this->assertSame('draft', $foreign->status);
        $this->assertNull($foreign->deleted_at);

        // A forged college_id never moves a new notice into another college.
        $this->asCollege($college, $user)
            ->post(route('notices.store'), $this->noticePayload(['title' => 'Stamped Notice', 'college_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $stamped = Notice::withoutGlobalScopes()->where('title', 'Stamped Notice')->firstOrFail();
        $this->assertSame($college->id, $stamped->college_id);
        $this->assertSame(0, $this->withTenant($other, fn () => Notice::query()->where('title', 'Stamped Notice')->count()));
    }

    public function test_crud_actions_require_the_matching_permission(): void
    {
        $college = $this->makeCollege('NTC02');
        $notice = $this->makeNotice($college, ['title' => 'Guarded Notice']);

        $viewer = $this->makeUserWithPermissions($college, ['notices.view']);
        $this->asCollege($college, $viewer)->get(route('notices.index'))->assertOk()->assertSee('Guarded Notice');
        $this->asCollege($college, $viewer)->get(route('notices.show', $notice))->assertOk();
        $this->asCollege($college, $viewer)->get(route('notices.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('notices.store'), $this->noticePayload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('notices.edit', $notice))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('notices.update', $notice), $this->noticePayload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('notices.destroy', $notice))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('notices.publish', $notice))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('notices.archive', $notice))->assertForbidden();

        // The read-only viewer is not offered write actions either.
        $this->asCollege($college, $viewer)
            ->get(route('notices.index'))
            ->assertDontSee(route('notices.create'), false)
            ->assertDontSee(route('notices.edit', $notice), false);

        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $this->asCollege($college, $stranger)->get(route('notices.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('notices.show', $notice))->assertForbidden();

        $creator = $this->makeUserWithPermissions($college, ['notices.view', 'notices.create']);
        $this->asCollege($college, $creator)->get(route('notices.create'))->assertOk();
        $this->asCollege($college, $creator)->post(route('notices.store'), $this->noticePayload())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Guarded Notice', $notice->refresh()->title);
        $this->assertNull($notice->deleted_at);
    }

    public function test_a_notice_can_be_created_updated_and_deleted_with_server_controlled_fields(): void
    {
        $college = $this->makeCollege('NTC03');
        $other = $this->makeCollege('NTC03X');
        $author = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);
        $editor = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        $this->asCollege($college, $author)->get(route('notices.create'))->assertOk();

        $this->asCollege($college, $author)
            ->post(route('notices.store'), $this->noticePayload([
                'college_id' => $other->id,
                'created_by' => 9999,
                'updated_by' => 9999,
                'status' => 'published',
                'slug' => 'forged-slug',
            ]))
            ->assertSessionHasNoErrors();

        $notice = $this->withTenant($college, fn () => Notice::query()->where('title', 'Campus closed on Friday')->firstOrFail());

        $this->assertSame($college->id, $notice->college_id);
        $this->assertSame($author->id, $notice->created_by);
        $this->assertSame($author->id, $notice->updated_by);
        $this->assertSame('draft', $notice->status, 'Status can only change through the publish workflow.');
        $this->assertSame('campus-closed-on-friday', $notice->slug);
        $this->assertSame('administrative', $notice->notice_type);
        $this->assertSame('important', $notice->priority);

        $this->asCollege($college, $author)
            ->get(route('notices.show', $notice))
            ->assertOk()
            ->assertSee('Campus closed on Friday')
            ->assertSee('Classes resume on Monday.');

        $this->asCollege($college, $editor)->get(route('notices.edit', $notice))->assertOk()->assertSee('Campus closed on Friday');

        $this->asCollege($college, $editor)
            ->put(route('notices.update', $notice), $this->noticePayload([
                'title' => 'Campus closed on Saturday',
                'notice_type' => 'Sports Event',
                'priority' => 'urgent',
                'created_by' => 9999,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('notices.show', $notice));

        $notice->refresh();
        $this->assertSame('Campus closed on Saturday', $notice->title);
        $this->assertSame('sports_event', $notice->notice_type, 'Types are extensible and stored normalised.');
        $this->assertSame('Sports Event', $notice->typeLabel());
        $this->assertSame('urgent', $notice->priority);
        $this->assertSame('campus-closed-on-saturday', $notice->slug, 'Draft slugs follow the title.');
        $this->assertSame($author->id, $notice->created_by);
        $this->assertSame($editor->id, $notice->updated_by);

        $this->asCollege($college, $editor)
            ->delete(route('notices.destroy', $notice))
            ->assertRedirect(route('notices.index'));

        $this->assertSoftDeleted('notices', ['id' => $notice->id]);
    }

    public function test_the_publish_workflow_follows_the_allowed_transitions(): void
    {
        $college = $this->makeCollege('NTC04');
        $publisher = $this->makeUserWithPermissions($college, ['notices.view', 'notices.update', 'notices.publish']);
        $notice = $this->makeNotice($college, ['title' => 'Workflow Notice', 'publish_at' => now()->subHour()]);

        $this->asCollege($college, $publisher)->post(route('notices.publish', $notice))->assertRedirect(route('notices.show', $notice));
        $notice->refresh();
        $this->assertSame('published', $notice->status);
        $this->assertSame('live', $notice->visibility());
        $this->assertSame($publisher->id, $notice->updated_by);

        // Publishing twice is not a valid transition.
        $this->asCollege($college, $publisher)->post(route('notices.publish', $notice))->assertSessionHasErrors('status');
        $this->assertSame('published', $notice->refresh()->status);

        $this->asCollege($college, $publisher)->post(route('notices.unpublish', $notice))->assertRedirect();
        $this->assertSame('draft', $notice->refresh()->status);

        $this->asCollege($college, $publisher)->post(route('notices.archive', $notice))->assertRedirect();
        $this->assertSame('archived', $notice->refresh()->status);

        // Archived notices are read-only…
        $this->asCollege($college, $publisher)->get(route('notices.edit', $notice))->assertRedirect(route('notices.show', $notice))->assertSessionHasErrors('notice');
        $this->asCollege($college, $publisher)->put(route('notices.update', $notice), $this->noticePayload(['title' => 'Edited while archived']))->assertSessionHasErrors('notice');
        $this->assertSame('Workflow Notice', $notice->refresh()->title);
        $this->asCollege($college, $publisher)->post(route('notices.archive', $notice))->assertSessionHasErrors('status');

        // …but can be published again (or restored to draft).
        $this->asCollege($college, $publisher)->post(route('notices.publish', $notice))->assertRedirect();
        $this->assertSame('published', $notice->refresh()->status);

        // A future publish date makes a published notice "scheduled".
        $scheduled = $this->makeNotice($college, ['publish_at' => now()->addDay()]);
        $this->asCollege($college, $publisher)->post(route('notices.publish', $scheduled))->assertRedirect();
        $this->assertSame('scheduled', $scheduled->refresh()->visibility());

        // An already-expired notice cannot be published.
        $expired = $this->makeNotice($college, ['publish_at' => now()->subDays(3), 'expires_at' => now()->subDay()]);
        $this->asCollege($college, $publisher)->post(route('notices.publish', $expired))->assertSessionHasErrors('expires_at');
        $this->assertSame('draft', $expired->refresh()->status);

        // Publishing needs notices.publish — notices.update alone is not enough.
        $editor = $this->makeUserWithPermissions($college, ['notices.view', 'notices.update']);
        $draft = $this->makeNotice($college);
        $this->asCollege($college, $editor)->post(route('notices.publish', $draft))->assertForbidden();
        $this->asCollege($college, $editor)->post(route('notices.unpublish', $notice))->assertForbidden();
        $this->asCollege($college, $editor)->post(route('notices.archive', $draft))->assertForbidden();
        $this->assertSame('draft', $draft->refresh()->status);

        // The workflow buttons render only for publishers.
        $this->asCollege($college, $editor)->get(route('notices.show', $draft))->assertOk()->assertDontSee(route('notices.publish', $draft), false);
        $this->asCollege($college, $publisher)->get(route('notices.show', $draft))->assertOk()->assertSee(route('notices.publish', $draft), false);
    }

    public function test_validation_rejects_invalid_payloads(): void
    {
        $college = $this->makeCollege('NTC05');
        $other = $this->makeCollege('NTC05X');
        $user = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        $this->asCollege($college, $user)
            ->post(route('notices.store'), [])
            ->assertSessionHasErrors(['title', 'notice_type', 'content', 'publish_at', 'priority', 'target_type']);

        $invalid = [
            'priority' => ['priority' => 'critical'],
            'target_type' => ['target_type' => 'alumni'],
            'expires_at' => ['expires_at' => now()->subDays(2)->format('Y-m-d\TH:i')],
            'notice_type' => ['notice_type' => '!!!'],
            'title' => ['title' => str_repeat('a', 256)],
            'publish_at' => ['publish_at' => 'not-a-date'],
        ];

        foreach ($invalid as $field => $overrides) {
            $this->asCollege($college, $user)
                ->post(route('notices.store'), $this->noticePayload($overrides))
                ->assertSessionHasErrors($field);
        }

        // Entity targets need a record of the ACTIVE college.
        $ownProgram = $this->makeProgram($college, 'B.Sc Physics');
        $foreignProgram = $this->makeProgram($other, 'Foreign Program');

        $this->asCollege($college, $user)
            ->post(route('notices.store'), $this->noticePayload(['target_type' => 'program']))
            ->assertSessionHasErrors('target_id');

        $this->asCollege($college, $user)
            ->post(route('notices.store'), $this->noticePayload(['target_type' => 'program', 'target_id' => $foreignProgram->id]))
            ->assertSessionHasErrors('target_id');

        $this->assertSame(0, Notice::withoutGlobalScopes()->count(), 'No invalid notice may be stored.');

        $this->asCollege($college, $user)
            ->post(route('notices.store'), $this->noticePayload(['title' => 'Physics notice', 'target_type' => 'program', 'target_id' => $ownProgram->id]))
            ->assertSessionHasNoErrors();

        $notice = Notice::withoutGlobalScopes()->where('title', 'Physics notice')->firstOrFail();
        $this->assertSame('program', $notice->target_type);
        $this->assertSame($ownProgram->id, $notice->target_id);

        $this->asCollege($college, $user)
            ->get(route('notices.show', $notice))
            ->assertOk()
            ->assertSee('Program: B.Sc Physics');

        // Audience-wide targets drop any stray target_id.
        $this->asCollege($college, $user)
            ->post(route('notices.store'), $this->noticePayload(['title' => 'Everyone notice', 'target_type' => 'staff', 'target_id' => $foreignProgram->id]))
            ->assertSessionHasNoErrors();
        $this->assertNull(Notice::withoutGlobalScopes()->where('title', 'Everyone notice')->value('target_id'));
    }

    public function test_pagination_is_deterministic_for_identical_publish_dates(): void
    {
        $college = $this->makeCollege('NTC06');
        $user = $this->makeUserWithPermissions($college, ['notices.view']);
        $at = now()->subDay()->startOfMinute();

        $ids = collect(range(1, 20))
            ->map(fn (int $i) => $this->makeNotice($college, ['title' => "Paged notice {$i}", 'publish_at' => $at])->id)
            ->sortDesc()
            ->values();

        $page1 = $this->asCollege($college, $user)->get(route('notices.index'))->assertOk()->viewData('notices');
        $page2 = $this->asCollege($college, $user)->get(route('notices.index', ['page' => 2]))->assertOk()->viewData('notices');
        $again = $this->asCollege($college, $user)->get(route('notices.index'))->viewData('notices');

        $first = $page1->getCollection()->pluck('id')->all();
        $second = $page2->getCollection()->pluck('id')->all();

        $this->assertSame(20, $page1->total());
        $this->assertSame($ids->take(15)->all(), $first);
        $this->assertSame($ids->slice(15)->values()->all(), $second);
        $this->assertSame([], array_intersect($first, $second));
        $this->assertSame($first, $again->getCollection()->pluck('id')->all(), 'Repeated requests return the same order.');
    }

    public function test_filters_narrow_the_listing(): void
    {
        $college = $this->makeCollege('NTC07');
        $user = $this->makeUserWithPermissions($college, ['notices.view']);

        $this->makeNotice($college, ['title' => 'Library timings', 'status' => 'published', 'priority' => 'urgent', 'notice_type' => 'academic', 'target_type' => 'students', 'publish_at' => '2026-03-10 09:00:00']);
        $this->makeNotice($college, ['title' => 'Sports day', 'status' => 'draft', 'priority' => 'normal', 'notice_type' => 'event', 'target_type' => 'staff', 'publish_at' => '2026-04-15 09:00:00']);
        $this->makeNotice($college, ['title' => 'Fee deadline', 'content' => 'Pay the tuition balance.', 'status' => 'archived', 'priority' => 'important', 'notice_type' => 'administrative', 'target_type' => 'all', 'publish_at' => '2026-05-20 09:00:00']);

        $titles = fn (array $query) => $this->asCollege($college, $user)
            ->get(route('notices.index', $query))
            ->assertOk()
            ->viewData('notices')
            ->getCollection()
            ->pluck('title')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Library timings'], $titles(['search' => 'Library']));
        $this->assertSame(['Fee deadline'], $titles(['search' => 'tuition']), 'Search covers the content.');
        $this->assertSame(['Sports day'], $titles(['status' => 'draft']));
        $this->assertSame(['Fee deadline'], $titles(['priority' => 'important']));
        $this->assertSame(['Sports day'], $titles(['notice_type' => 'Event']));
        $this->assertSame(['Sports day'], $titles(['target_type' => 'staff']));
        $this->assertSame(['Sports day'], $titles(['date_from' => '2026-04-01', 'date_to' => '2026-04-30']));
        $this->assertSame(['Fee deadline', 'Sports day'], $titles(['date_from' => '2026-04-15']));
        $this->assertSame(['Library timings'], $titles(['status' => 'published', 'priority' => 'urgent']));

        // Malformed filters are ignored, never widened or errored.
        $all = ['Fee deadline', 'Library timings', 'Sports day'];
        $this->assertSame($all, $titles(['status' => 'bogus']));
        $this->assertSame($all, $titles(['status' => ['draft']]));
        $this->assertSame($all, $titles(['date_from' => '2026-02-31']));
        $this->assertSame($all, $titles(['priority' => 'critical', 'target_type' => 'alumni']));
    }

    public function test_deleted_notices_are_soft_deleted_and_keep_their_slug_reserved(): void
    {
        $college = $this->makeCollege('NTC08');
        $user = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        $this->asCollege($college, $user)->post(route('notices.store'), $this->noticePayload(['title' => 'Old notice']))->assertSessionHasNoErrors();
        $notice = Notice::withoutGlobalScopes()->where('title', 'Old notice')->firstOrFail();
        $this->assertSame('old-notice', $notice->slug);

        $this->asCollege($college, $user)->delete(route('notices.destroy', $notice))->assertRedirect(route('notices.index'));

        $this->assertSoftDeleted('notices', ['id' => $notice->id]);
        $this->assertSame(1, Notice::withoutGlobalScopes()->withTrashed()->whereKey($notice->id)->count(), 'The row is kept.');
        $listed = $this->asCollege($college, $user)->get(route('notices.index'))->assertOk()->viewData('notices')->getCollection()->pluck('id')->all();
        $this->assertNotContains($notice->id, $listed, 'Deleted notices leave the listing.');
        $this->asCollege($college, $user)->get(route('notices.show', $notice))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('notices.destroy', $notice))->assertNotFound();

        // Slugs stay unique per college even against archived notices.
        $this->asCollege($college, $user)->post(route('notices.store'), $this->noticePayload(['title' => 'Old notice']))->assertSessionHasNoErrors();
        $this->assertSame('old-notice-2', Notice::withoutGlobalScopes()->whereNull('deleted_at')->where('title', 'Old notice')->value('slug'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'notices.deleted', 'subject_id' => $notice->id, 'college_id' => $college->id, 'user_id' => $user->id]);
    }

    public function test_attachment_downloads_are_private_and_authorized(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('NTC09');
        $other = $this->makeCollege('NTC09X');
        $manager = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        $this->asCollege($college, $manager)
            ->post(route('notices.store'), $this->noticePayload([
                'title' => 'Notice with file',
                'attachment' => UploadedFile::fake()->create('Exam Schedule.pdf', 120, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $notice = Notice::withoutGlobalScopes()->where('title', 'Notice with file')->firstOrFail();

        $this->assertStringStartsWith("communication/notices/{$college->id}/", $notice->attachment_path);
        $this->assertStringEndsWith('.pdf', $notice->attachment_path);
        $this->assertStringNotContainsString('Exam Schedule', $notice->attachment_path, 'Stored names are generated, never the upload name.');
        $this->assertSame('Exam Schedule.pdf', $notice->attachment_name);
        Storage::disk('private')->assertExists($notice->attachment_path);

        $this->asCollege($college, $manager)
            ->get(route('notices.attachment', $notice))
            ->assertOk()
            ->assertDownload('Exam Schedule.pdf');

        $this->assertDatabaseHas('audit_logs', ['action' => 'notices.attachment_downloaded', 'subject_id' => $notice->id, 'user_id' => $manager->id, 'college_id' => $college->id]);

        $noView = $this->makeUserWithPermissions($college, ['notices.create']);
        $this->asCollege($college, $noView)->get(route('notices.attachment', $notice))->assertForbidden();

        $outsider = $this->makeUserWithPermissions($other, self::NOTICE_PERMISSIONS);
        $this->asCollege($other, $outsider)->get(route('notices.attachment', $notice))->assertNotFound();

        $plain = $this->makeNotice($college);
        $this->asCollege($college, $manager)->get(route('notices.attachment', $plain))->assertNotFound();

        // A non-ASCII upload name is shown as uploaded but downloads under a
        // safe ASCII name that keeps the validated extension.
        $this->asCollege($college, $manager)
            ->post(route('notices.store'), $this->noticePayload([
                'title' => 'Unicode file notice',
                'attachment' => UploadedFile::fake()->create('परीक्षा.pdf', 10, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();
        $unicode = Notice::withoutGlobalScopes()->where('title', 'Unicode file notice')->firstOrFail();
        $this->assertSame('परीक्षा.pdf', $unicode->attachment_name);
        $this->asCollege($college, $manager)
            ->get(route('notices.attachment', $unicode))
            ->assertOk()
            ->assertDownload("notice-{$unicode->id}.pdf");

        // Replacing the file removes the old one; removing clears the columns.
        $oldPath = $notice->attachment_path;
        $this->asCollege($college, $manager)
            ->put(route('notices.update', $notice), $this->noticePayload([
                'title' => 'Notice with file',
                'attachment' => UploadedFile::fake()->create('Revised.pdf', 50, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $notice->refresh();
        $this->assertNotSame($oldPath, $notice->attachment_path);
        $this->assertSame('Revised.pdf', $notice->attachment_name);
        Storage::disk('private')->assertMissing($oldPath);
        Storage::disk('private')->assertExists($notice->attachment_path);

        $replaced = $notice->attachment_path;
        $this->asCollege($college, $manager)
            ->put(route('notices.update', $notice), $this->noticePayload(['title' => 'Notice with file', 'remove_attachment' => '1']))
            ->assertSessionHasNoErrors();

        $notice->refresh();
        $this->assertNull($notice->attachment_path);
        $this->assertNull($notice->attachment_name);
        Storage::disk('private')->assertMissing($replaced);
    }

    public function test_attachment_path_traversal_and_unsafe_files_are_rejected(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('NTC10');
        $other = $this->makeCollege('NTC10X');
        $user = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        Storage::disk('private')->put('secret.txt', 'top secret');
        Storage::disk('private')->put("communication/notices/{$other->id}/leak.pdf", 'other college');

        $tampered = [
            '../../../.env',
            "communication/notices/{$college->id}/../../../secret.txt",
            'secret.txt',
            "communication/notices/{$other->id}/leak.pdf",
            "communication/circulars/{$college->id}/0b8c7d1e-0000-4000-8000-000000000000.pdf",
            '/etc/passwd',
            "communication\\notices\\{$college->id}\\x.pdf",
            'file:///etc/passwd',
            "communication/notices/{$college->id}/nested/dir.pdf",
        ];

        foreach ($tampered as $path) {
            $notice = $this->makeNotice($college, ['attachment_path' => $path, 'attachment_name' => 'evil.pdf']);

            $this->asCollege($college, $user)
                ->get(route('notices.attachment', $notice))
                ->assertForbidden();
        }

        // A forged path in the payload is ignored; the stored key is generated.
        $this->asCollege($college, $user)
            ->post(route('notices.store'), $this->noticePayload([
                'title' => 'Forged path notice',
                'attachment_path' => '../../.env',
                'attachment_name' => 'evil.php',
            ]))
            ->assertSessionHasNoErrors();

        $forged = Notice::withoutGlobalScopes()->where('title', 'Forged path notice')->firstOrFail();
        $this->assertNull($forged->attachment_path);
        $this->assertNull($forged->attachment_name);

        // Executable / scriptable / oversize uploads are refused.
        $unsafe = [
            UploadedFile::fake()->create('shell.php', 5, 'text/x-php'),
            UploadedFile::fake()->create('page.html', 5, 'text/html'),
            UploadedFile::fake()->create('vector.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('tool.exe', 5, 'application/x-msdownload'),
            UploadedFile::fake()->create('huge.pdf', CommunicationAttachmentService::maxKilobytes() + 100, 'application/pdf'),
        ];

        foreach ($unsafe as $file) {
            $this->asCollege($college, $user)
                ->post(route('notices.store'), $this->noticePayload(['title' => 'Unsafe upload', 'attachment' => $file]))
                ->assertSessionHasErrors('attachment');
        }

        $this->assertSame(0, Notice::withoutGlobalScopes()->where('title', 'Unsafe upload')->count());

        // Download names are reduced to one safe header segment.
        $this->assertSame('evilname.pdf', CommunicationAttachmentService::safeDownloadName("..\\../evil\"name\r\n.pdf"));
        $this->assertSame('notice-1', CommunicationAttachmentService::safeDownloadName('../..', 'notice-1'));
    }

    public function test_audit_fields_and_events_are_recorded(): void
    {
        $college = $this->makeCollege('NTC11');
        $author = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);
        $editor = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);

        $this->asCollege($college, $author)->post(route('notices.store'), $this->noticePayload(['title' => 'Audited notice']))->assertSessionHasNoErrors();
        $notice = Notice::withoutGlobalScopes()->where('title', 'Audited notice')->firstOrFail();

        $this->asCollege($college, $editor)->put(route('notices.update', $notice), $this->noticePayload(['title' => 'Audited notice v2']))->assertSessionHasNoErrors();
        $this->asCollege($college, $editor)->post(route('notices.publish', $notice))->assertRedirect();
        $this->asCollege($college, $editor)->post(route('notices.unpublish', $notice))->assertRedirect();
        $this->asCollege($college, $editor)->post(route('notices.archive', $notice))->assertRedirect();
        $this->asCollege($college, $editor)->delete(route('notices.destroy', $notice))->assertRedirect();

        $notice = Notice::withoutGlobalScopes()->withTrashed()->findOrFail($notice->id);
        $this->assertSame($author->id, $notice->created_by);
        $this->assertSame($editor->id, $notice->updated_by);

        $logs = AuditLog::query()->where('subject_type', $notice->getMorphClass())->where('subject_id', $notice->id)->orderBy('id')->get();

        $this->assertSame(
            ['notices.created', 'notices.updated', 'notices.published', 'notices.unpublished', 'notices.archived', 'notices.deleted'],
            $logs->pluck('action')->all(),
        );
        $this->assertTrue($logs->every(fn (AuditLog $log) => $log->college_id === $college->id));
        $this->assertSame($author->id, $logs[0]->user_id);
        $this->assertSame($editor->id, $logs[1]->user_id);

        // Updates record only the fields that changed.
        $this->assertSame('Audited notice', $logs[1]->old_values['title']);
        $this->assertSame('Audited notice v2', $logs[1]->new_values['title']);
        $this->assertArrayNotHasKey('content', $logs[1]->new_values);
        $this->assertSame(['status' => 'draft'], $logs[2]->old_values);
        $this->assertSame(['status' => 'published'], $logs[2]->new_values);
    }

    public function test_user_content_is_rendered_escaped(): void
    {
        $college = $this->makeCollege('NTC12');
        $user = $this->makeUserWithPermissions($college, self::NOTICE_PERMISSIONS);
        $title = '<script>alert("x")</script>';
        $content = '<img src=x onerror=alert(1)>';
        $notice = $this->makeNotice($college, ['title' => $title, 'content' => $content]);
        $breakout = $this->makeNotice($college, ['title' => "Bob'); alert(1);//"]);

        $this->asCollege($college, $user)
            ->get(route('notices.index'))
            ->assertOk()
            ->assertDontSee($title, false)
            ->assertSee(e($title), false)
            ->assertDontSee("Bob'); alert(1);//", false);

        $this->asCollege($college, $user)
            ->get(route('notices.show', $notice))
            ->assertOk()
            ->assertDontSee($title, false)
            ->assertDontSee($content, false)
            ->assertSee(e($content), false);

        $this->asCollege($college, $user)
            ->get(route('notices.show', $breakout))
            ->assertOk()
            ->assertDontSee("Bob'); alert(1);//", false);
    }
}
