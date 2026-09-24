<?php

namespace Tests\Feature\Communication;

use App\Models\AuditLog;
use App\Models\Circular;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Communication Management — Circulars.
 *
 * A separate module from Notices: tenant isolation, circular-number
 * uniqueness per college (archived rows included, frozen after publication),
 * RBAC, the publication workflow, validation, deterministic pagination,
 * secure attachments and the audit trail.
 */
class CircularTest extends TestCase
{
    use CommunicationTestHelpers;

    public function test_circulars_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('CIR01');
        $other = $this->makeCollege('CIR01X');
        $user = $this->makeUserWithPermissions($college, self::CIRCULAR_PERMISSIONS);

        $this->makeCircular($college, ['title' => 'Our Circular']);
        $foreign = $this->makeCircular($other, ['title' => 'Foreign Circular', 'circular_number' => 'FOREIGN/1']);

        $this->asCollege($college, $user)
            ->get(route('circulars.index'))
            ->assertOk()
            ->assertSee('Our Circular')
            ->assertDontSee('Foreign Circular')
            ->assertDontSee('FOREIGN/1');

        foreach (['circulars.show', 'circulars.edit', 'circulars.attachment'] as $route) {
            $this->asCollege($college, $user)->get(route($route, $foreign))->assertNotFound();
        }

        $this->asCollege($college, $user)->put(route('circulars.update', $foreign), $this->circularPayload(['circular_number' => 'FOREIGN/1']))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('circulars.destroy', $foreign))->assertNotFound();

        foreach (['circulars.publish', 'circulars.unpublish', 'circulars.archive'] as $route) {
            $this->asCollege($college, $user)->post(route($route, $foreign))->assertNotFound();
        }

        $foreign->refresh();
        $this->assertSame('Foreign Circular', $foreign->title);
        $this->assertSame('draft', $foreign->status);
        $this->assertNull($foreign->deleted_at);
    }

    public function test_circular_numbers_are_unique_within_a_college_only(): void
    {
        $college = $this->makeCollege('CIR02');
        $other = $this->makeCollege('CIR02X');
        $user = $this->makeUserWithPermissions($college, self::CIRCULAR_PERMISSIONS);
        $otherUser = $this->makeUserWithPermissions($other, self::CIRCULAR_PERMISSIONS);

        $existing = $this->makeCircular($college, ['circular_number' => 'CIR/01']);

        // Case- and whitespace-insensitive duplicate within the college.
        $this->asCollege($college, $user)
            ->post(route('circulars.store'), $this->circularPayload(['circular_number' => '  cir/01 ']))
            ->assertSessionHasErrors('circular_number');

        // The same number is free in another college.
        $this->asCollege($other, $otherUser)
            ->post(route('circulars.store'), $this->circularPayload(['circular_number' => 'CIR/01']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->withTenant($other, fn () => Circular::query()->where('circular_number', 'CIR/01')->count()));

        // Numbers stay reserved after the circular is deleted.
        $this->asCollege($college, $user)->delete(route('circulars.destroy', $existing))->assertRedirect();
        $this->assertSoftDeleted('circulars', ['id' => $existing->id]);
        $this->asCollege($college, $user)
            ->post(route('circulars.store'), $this->circularPayload(['circular_number' => 'CIR/01']))
            ->assertSessionHasErrors('circular_number');

        // Updates keep the number unique among the college's other circulars.
        $second = $this->makeCircular($college, ['circular_number' => 'CIR/02']);
        $this->asCollege($college, $user)
            ->put(route('circulars.update', $second), $this->circularPayload(['circular_number' => 'CIR/01']))
            ->assertSessionHasErrors('circular_number');
        $this->asCollege($college, $user)
            ->put(route('circulars.update', $second), $this->circularPayload(['circular_number' => 'cir/02', 'title' => 'Same number kept']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Same number kept', $second->refresh()->title);
        $this->assertSame('CIR/02', $second->circular_number);

        // Once published, the number is frozen.
        $published = $this->makeCircular($college, ['circular_number' => 'CIR/03', 'status' => 'published', 'publish_at' => now()->subHour()]);
        $this->asCollege($college, $user)->get(route('circulars.edit', $published))->assertOk()->assertSee('readonly', false)->assertSee('Frozen after publication.');
        $this->asCollege($college, $user)
            ->put(route('circulars.update', $published), $this->circularPayload(['circular_number' => 'CIR/99']))
            ->assertSessionHasErrors('circular_number');
        $this->assertSame('CIR/03', $published->refresh()->circular_number);

        // The database enforces the same rule.
        $this->expectException(QueryException::class);
        $this->makeCircular($college, ['circular_number' => 'CIR/02']);
    }

    public function test_crud_actions_require_the_matching_permission(): void
    {
        $college = $this->makeCollege('CIR03');
        $circular = $this->makeCircular($college, ['title' => 'Guarded Circular']);

        $viewer = $this->makeUserWithPermissions($college, ['circulars.view']);
        $this->asCollege($college, $viewer)->get(route('circulars.index'))->assertOk()->assertSee('Guarded Circular');
        $this->asCollege($college, $viewer)->get(route('circulars.show', $circular))->assertOk();
        $this->asCollege($college, $viewer)->get(route('circulars.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('circulars.store'), $this->circularPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('circulars.edit', $circular))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('circulars.update', $circular), $this->circularPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('circulars.destroy', $circular))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('circulars.publish', $circular))->assertForbidden();

        $stranger = $this->makeUserWithPermissions($college, ['notices.view']);
        $this->asCollege($college, $stranger)->get(route('circulars.index'))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('circulars.show', $circular))->assertForbidden();

        $this->assertSame('Guarded Circular', $circular->refresh()->title);
        $this->assertSame(1, Circular::withoutGlobalScopes()->count());
    }

    public function test_a_circular_can_be_created_updated_and_deleted_with_server_controlled_fields(): void
    {
        $college = $this->makeCollege('CIR04');
        $other = $this->makeCollege('CIR04X');
        $author = $this->makeUserWithPermissions($college, self::CIRCULAR_PERMISSIONS);
        $editor = $this->makeUserWithPermissions($college, self::CIRCULAR_PERMISSIONS);

        $this->asCollege($college, $author)->get(route('circulars.create'))->assertOk();

        $this->asCollege($college, $author)
            ->post(route('circulars.store'), $this->circularPayload([
                'circular_number' => ' acad/2026/07 ',
                'college_id' => $other->id,
                'created_by' => 9999,
                'status' => 'published',
            ]))
            ->assertSessionHasNoErrors();

        $circular = $this->withTenant($college, fn () => Circular::query()->where('circular_number', 'ACAD/2026/07')->firstOrFail());
        $this->assertSame($college->id, $circular->college_id);
        $this->assertSame($author->id, $circular->created_by);
        $this->assertSame($author->id, $circular->updated_by);
        $this->assertSame('draft', $circular->status);
        $this->assertSame('students', $circular->target_type);

        $this->asCollege($college, $author)->get(route('circulars.show', $circular))->assertOk()->assertSee('ACAD/2026/07')->assertSee('Changes to the mid-term schedule');
        $this->asCollege($college, $editor)->get(route('circulars.edit', $circular))->assertOk()->assertSee('ACAD/2026/07')->assertDontSee('readonly', false);

        $this->asCollege($college, $editor)
            ->put(route('circulars.update', $circular), $this->circularPayload([
                'circular_number' => 'ACAD/2026/07',
                'title' => 'Updated circular title',
                'target_type' => 'staff',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('circulars.show', $circular));

        $circular->refresh();
        $this->assertSame('Updated circular title', $circular->title);
        $this->assertSame('staff', $circular->target_type);
        $this->assertSame($editor->id, $circular->updated_by);

        $this->asCollege($college, $editor)->delete(route('circulars.destroy', $circular))->assertRedirect(route('circulars.index'));
        $this->assertSoftDeleted('circulars', ['id' => $circular->id]);

        $actions = AuditLog::query()->where('subject_type', $circular->getMorphClass())->where('subject_id', $circular->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['circulars.created', 'circulars.updated', 'circulars.deleted'], $actions);
    }

    public function test_the_publish_workflow_follows_the_allowed_transitions(): void
    {
        $college = $this->makeCollege('CIR05');
        $publisher = $this->makeUserWithPermissions($college, ['circulars.view', 'circulars.update', 'circulars.publish']);
        $circular = $this->makeCircular($college, ['publish_at' => null]);

        $this->asCollege($college, $publisher)->post(route('circulars.publish', $circular))->assertRedirect(route('circulars.show', $circular));
        $circular->refresh();
        $this->assertSame('published', $circular->status);
        $this->assertNotNull($circular->publish_at, 'Publishing without a schedule stamps the publication moment.');
        $this->assertTrue($circular->publish_at->lte(now()));
        $this->assertSame('live', $circular->visibility());
        $this->assertSame($publisher->id, $circular->updated_by);

        $this->asCollege($college, $publisher)->post(route('circulars.publish', $circular))->assertSessionHasErrors('status');

        $this->asCollege($college, $publisher)->post(route('circulars.unpublish', $circular))->assertRedirect();
        $this->assertSame('draft', $circular->refresh()->status);

        $this->asCollege($college, $publisher)->post(route('circulars.archive', $circular))->assertRedirect();
        $this->assertSame('archived', $circular->refresh()->status);
        $this->asCollege($college, $publisher)->get(route('circulars.edit', $circular))->assertRedirect(route('circulars.show', $circular));
        $this->asCollege($college, $publisher)->put(route('circulars.update', $circular), $this->circularPayload(['circular_number' => $circular->circular_number]))->assertSessionHasErrors('circular');

        $this->asCollege($college, $publisher)->post(route('circulars.unpublish', $circular))->assertRedirect();
        $this->assertSame('draft', $circular->refresh()->status, 'Archived circulars can be restored to draft.');

        // A scheduled publish date is kept.
        $scheduledAt = now()->addDays(2)->startOfMinute();
        $scheduled = $this->makeCircular($college, ['publish_at' => $scheduledAt]);
        $this->asCollege($college, $publisher)->post(route('circulars.publish', $scheduled))->assertRedirect();
        $scheduled->refresh();
        $this->assertTrue($scheduled->publish_at->equalTo($scheduledAt));
        $this->assertSame('scheduled', $scheduled->visibility());

        // Expired circulars cannot be published.
        $expired = $this->makeCircular($college, ['issue_date' => now()->subDays(10)->toDateString(), 'expires_at' => now()->subDay()]);
        $this->asCollege($college, $publisher)->post(route('circulars.publish', $expired))->assertSessionHasErrors('expires_at');
        $this->assertSame('draft', $expired->refresh()->status);

        // circulars.publish is required.
        $editor = $this->makeUserWithPermissions($college, ['circulars.view', 'circulars.update']);
        $draft = $this->makeCircular($college);
        $this->asCollege($college, $editor)->post(route('circulars.publish', $draft))->assertForbidden();
        $this->asCollege($college, $editor)->post(route('circulars.archive', $draft))->assertForbidden();
        $this->assertSame('draft', $draft->refresh()->status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'circulars.published', 'subject_id' => $circular->id, 'user_id' => $publisher->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'circulars.archived', 'subject_id' => $circular->id]);
    }

    public function test_validation_rejects_invalid_payloads(): void
    {
        $college = $this->makeCollege('CIR06');
        $user = $this->makeUserWithPermissions($college, self::CIRCULAR_PERMISSIONS);

        $this->asCollege($college, $user)
            ->post(route('circulars.store'), [])
            ->assertSessionHasErrors(['circular_number', 'title', 'subject', 'content', 'issue_date', 'target_type']);

        $invalid = [
            'circular_number' => ['circular_number' => '**bad**'],
            'issue_date' => ['issue_date' => '24/09/2026'],
            'target_type' => ['target_type' => 'program'],
            'expires_at' => ['issue_date' => '2026-09-20', 'expires_at' => '2026-09-10T10:00'],
            'subject' => ['subject' => str_repeat('s', 256)],
        ];

        foreach ($invalid as $field => $overrides) {
            $this->asCollege($college, $user)
                ->post(route('circulars.store'), $this->circularPayload($overrides))
                ->assertSessionHasErrors($field);
        }

        // Expiry must follow the publish date when one is set.
        $this->asCollege($college, $user)
            ->post(route('circulars.store'), $this->circularPayload([
                'issue_date' => '2026-09-01',
                'publish_at' => '2026-09-20T10:00',
                'expires_at' => '2026-09-15T10:00',
            ]))
            ->assertSessionHasErrors('expires_at');

        $this->assertSame(0, Circular::withoutGlobalScopes()->count());
    }

    public function test_pagination_is_deterministic_for_identical_issue_dates(): void
    {
        $college = $this->makeCollege('CIR07');
        $user = $this->makeUserWithPermissions($college, ['circulars.view']);

        $ids = collect(range(1, 20))
            ->map(fn (int $i) => $this->makeCircular($college, ['circular_number' => "PAGE/{$i}", 'issue_date' => '2026-09-01'])->id)
            ->sortDesc()
            ->values();

        $page1 = $this->asCollege($college, $user)->get(route('circulars.index'))->assertOk()->viewData('circulars');
        $page2 = $this->asCollege($college, $user)->get(route('circulars.index', ['page' => 2]))->assertOk()->viewData('circulars');

        $first = $page1->getCollection()->pluck('id')->all();
        $second = $page2->getCollection()->pluck('id')->all();

        $this->assertSame(20, $page1->total());
        $this->assertSame($ids->take(15)->all(), $first);
        $this->assertSame($ids->slice(15)->values()->all(), $second);
        $this->assertSame([], array_intersect($first, $second));

        // Filters: status + issue-date range + search.
        $this->makeCircular($college, ['circular_number' => 'EXAM/9', 'title' => 'Exam circular', 'status' => 'published', 'publish_at' => now(), 'issue_date' => '2026-06-15']);
        $filtered = $this->asCollege($college, $user)
            ->get(route('circulars.index', ['status' => 'published', 'date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'search' => 'EXAM']))
            ->viewData('circulars');
        $this->assertSame(['EXAM/9'], $filtered->getCollection()->pluck('circular_number')->all());
    }

    public function test_attachments_are_private_authorized_and_traversal_safe(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('CIR08');
        $other = $this->makeCollege('CIR08X');
        $manager = $this->makeUserWithPermissions($college, self::CIRCULAR_PERMISSIONS);

        $this->asCollege($college, $manager)
            ->post(route('circulars.store'), $this->circularPayload([
                'circular_number' => 'FILE/1',
                'attachment' => UploadedFile::fake()->create('Circular Copy.pdf', 80, 'application/pdf'),
                'attachment_path' => '../../.env',
            ]))
            ->assertSessionHasNoErrors();

        $circular = Circular::withoutGlobalScopes()->where('circular_number', 'FILE/1')->firstOrFail();
        $this->assertStringStartsWith("communication/circulars/{$college->id}/", $circular->attachment_path);
        $this->assertStringNotContainsString('..', $circular->attachment_path);
        Storage::disk('private')->assertExists($circular->attachment_path);

        $this->asCollege($college, $manager)
            ->get(route('circulars.attachment', $circular))
            ->assertOk()
            ->assertDownload('Circular Copy.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'circulars.attachment_downloaded', 'subject_id' => $circular->id, 'user_id' => $manager->id]);

        $noView = $this->makeUserWithPermissions($college, ['circulars.create']);
        $this->asCollege($college, $noView)->get(route('circulars.attachment', $circular))->assertForbidden();

        $outsider = $this->makeUserWithPermissions($other, self::CIRCULAR_PERMISSIONS);
        $this->asCollege($other, $outsider)->get(route('circulars.attachment', $circular))->assertNotFound();

        // Tampered keys — traversal, other college, other area — are refused.
        Storage::disk('private')->put("communication/circulars/{$other->id}/leak.pdf", 'x');
        foreach ([
            "communication/circulars/{$college->id}/../../../.env",
            "communication/circulars/{$other->id}/leak.pdf",
            "communication/notices/{$college->id}/0b8c7d1e-0000-4000-8000-000000000000.pdf",
            '../secret.pdf',
        ] as $path) {
            $tampered = $this->makeCircular($college, ['attachment_path' => $path, 'attachment_name' => 'x.pdf']);
            $this->asCollege($college, $manager)->get(route('circulars.attachment', $tampered))->assertForbidden();
        }

        $this->asCollege($college, $manager)
            ->post(route('circulars.store'), $this->circularPayload([
                'circular_number' => 'FILE/2',
                'attachment' => UploadedFile::fake()->create('macro.php', 5, 'text/x-php'),
            ]))
            ->assertSessionHasErrors('attachment');
        $this->assertSame(0, Circular::withoutGlobalScopes()->where('circular_number', 'FILE/2')->count());
    }
}
