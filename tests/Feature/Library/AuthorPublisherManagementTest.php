<?php

namespace Tests\Feature\Library;

use App\Models\AuditLog;
use App\Models\Author;
use App\Models\Publisher;
use Tests\TestCase;

/**
 * Library Management — Authors / Publishers (reusable references).
 *
 * Both masters share the same invariants: strict college scoping, permission
 * gating, no duplicate names within a college (compared case- and
 * whitespace-insensitively), audit logging, and a delete guard while books
 * still reference the record.
 */
class AuthorPublisherManagementTest extends TestCase
{
    use LibraryTestHelpers;

    private function authorPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Donald E. Knuth',
            'status' => Author::STATUS_ACTIVE,
            'description' => 'Author of The Art of Computer Programming',
        ], $overrides);
    }

    private function publisherPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Addison-Wesley',
            'email' => 'orders@example.test',
            'phone' => '+1 555 0100',
            'website' => 'https://www.example.test',
            'address' => 'Boston, MA',
            'description' => 'Technical publisher',
            'status' => Publisher::STATUS_ACTIVE,
        ], $overrides);
    }

    // ----------------------------------------------------------------- Authors

    public function test_an_author_can_be_created_listed_updated_and_deleted(): void
    {
        $college = $this->makeCollege('LAUT1');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.create', 'authors.update', 'authors.delete']);

        $this->asCollege($college, $user)
            ->post(route('authors.store'), $this->authorPayload(['college_id' => 999, 'created_by' => 999]))
            ->assertRedirect(route('authors.index'));

        $author = $this->withTenant($college, fn () => Author::query()->where('name', 'Donald E. Knuth')->firstOrFail());
        $this->assertSame($college->id, $author->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $author->created_by);
        $this->assertSame('donald e. knuth', $author->name_normalized);

        $this->asCollege($college, $user)->get(route('authors.index'))->assertOk()->assertSee('Donald E. Knuth');
        $this->asCollege($college, $user)->get(route('authors.edit', $author))->assertOk()->assertSee('Donald E. Knuth');

        $this->asCollege($college, $user)
            ->put(route('authors.update', $author), $this->authorPayload(['name' => 'D. E. Knuth', 'status' => Author::STATUS_INACTIVE]))
            ->assertRedirect(route('authors.index'));

        $author->refresh();
        $this->assertSame('D. E. Knuth', $author->name);
        $this->assertSame(Author::STATUS_INACTIVE, $author->status);
        $this->assertSame($user->id, $author->updated_by);

        $this->asCollege($college, $user)->delete(route('authors.destroy', $author))->assertRedirect(route('authors.index'));
        $this->assertSoftDeleted('authors', ['id' => $author->id]);
    }

    public function test_duplicate_author_names_are_rejected_within_a_college_ignoring_case_and_spacing(): void
    {
        $college = $this->makeCollege('LAUT2');
        $other = $this->makeCollege('LAUT2X');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.create']);
        $this->makeAuthor($college, ['name' => 'Jane Austen']);
        $this->makeAuthor($other, ['name' => 'Charles Dickens']);

        $this->asCollege($college, $user)
            ->post(route('authors.store'), $this->authorPayload(['name' => '  jane   AUSTEN ']))
            ->assertSessionHasErrors('name');

        // The other college's author name is free in this college.
        $this->asCollege($college, $user)
            ->post(route('authors.store'), $this->authorPayload(['name' => 'Charles Dickens']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => Author::query()->where('name_normalized', 'jane austen')->count()));
        $this->assertSame(1, $this->withTenant($college, fn () => Author::query()->where('name_normalized', 'charles dickens')->count()));
    }

    public function test_an_author_name_freed_by_a_soft_delete_can_be_reused_and_updates_respect_uniqueness(): void
    {
        $college = $this->makeCollege('LAUT3');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.create', 'authors.update', 'authors.delete']);
        $taken = $this->makeAuthor($college, ['name' => 'Taken Name']);
        $author = $this->makeAuthor($college, ['name' => 'My Name']);

        $this->asCollege($college, $user)
            ->put(route('authors.update', $author), $this->authorPayload(['name' => 'taken name']))
            ->assertSessionHasErrors('name');

        $this->asCollege($college, $user)
            ->put(route('authors.update', $author), $this->authorPayload(['name' => 'My Name']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)->delete(route('authors.destroy', $taken))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('authors.store'), $this->authorPayload(['name' => 'Taken Name']))
            ->assertSessionHasNoErrors();
    }

    public function test_author_validation_rejects_incomplete_payloads(): void
    {
        $college = $this->makeCollege('LAUT4');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.create']);

        $this->asCollege($college, $user)->post(route('authors.store'), [])->assertSessionHasErrors(['name', 'status']);
        $this->asCollege($college, $user)->post(route('authors.store'), $this->authorPayload(['status' => 'retired']))->assertSessionHasErrors('status');
        $this->asCollege($college, $user)->post(route('authors.store'), $this->authorPayload(['name' => '   ']))->assertSessionHasErrors('name');
    }

    public function test_an_author_credited_on_books_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('LAUT5');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.delete']);
        $author = $this->makeAuthor($college);
        $this->makeBook($college, [], [$author]);

        $this->asCollege($college, $user)
            ->from(route('authors.index'))
            ->delete(route('authors.destroy', $author))
            ->assertRedirect(route('authors.index'))
            ->assertSessionHasErrors('author');

        $this->assertDatabaseHas('authors', ['id' => $author->id, 'deleted_at' => null]);
    }

    public function test_authors_are_isolated_per_college_and_permission_gated(): void
    {
        $college = $this->makeCollege('LAUT6');
        $other = $this->makeCollege('LAUT6X');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.update', 'authors.delete']);
        $viewer = $this->makeUserWithPermissions($college, ['authors.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $mine = $this->makeAuthor($college, ['name' => 'Mine Only']);
        $theirs = $this->makeAuthor($other, ['name' => 'Theirs Only']);

        $this->asCollege($college, $user)->get(route('authors.index'))->assertOk()->assertSee('Mine Only')->assertDontSee('Theirs Only');
        $this->asCollege($college, $user)->get(route('authors.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('authors.update', $theirs), $this->authorPayload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('authors.destroy', $theirs))->assertNotFound();
        $this->assertDatabaseHas('authors', ['id' => $theirs->id, 'name' => 'Theirs Only', 'deleted_at' => null]);

        $this->asCollege($college, $nobody)->get(route('authors.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('authors.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('authors.store'), $this->authorPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('authors.edit', $mine))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('authors.update', $mine), $this->authorPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('authors.destroy', $mine))->assertForbidden();
    }

    public function test_author_changes_are_audited(): void
    {
        $college = $this->makeCollege('LAUT7');
        $user = $this->makeUserWithPermissions($college, ['authors.view', 'authors.create', 'authors.update', 'authors.delete']);

        $this->asCollege($college, $user)->post(route('authors.store'), $this->authorPayload())->assertRedirect();
        $author = $this->withTenant($college, fn () => Author::query()->where('name', 'Donald E. Knuth')->firstOrFail());
        $this->asCollege($college, $user)->put(route('authors.update', $author), $this->authorPayload(['name' => 'Renamed']))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('authors.destroy', $author))->assertRedirect();

        $actions = AuditLog::query()->where('subject_type', Author::class)->where('subject_id', $author->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['authors.created', 'authors.updated', 'authors.deleted'], $actions);

        $updated = AuditLog::query()->where('action', 'authors.updated')->where('subject_id', $author->id)->firstOrFail();
        $this->assertSame('Donald E. Knuth', $updated->old_values['name']);
        $this->assertSame('Renamed', $updated->new_values['name']);
        $this->assertSame($college->id, $updated->college_id);
    }

    // -------------------------------------------------------------- Publishers

    public function test_a_publisher_can_be_created_listed_updated_and_deleted(): void
    {
        $college = $this->makeCollege('LPUB1');
        $user = $this->makeUserWithPermissions($college, ['publishers.view', 'publishers.create', 'publishers.update', 'publishers.delete']);

        $this->asCollege($college, $user)
            ->post(route('publishers.store'), $this->publisherPayload(['college_id' => 999, 'created_by' => 999]))
            ->assertRedirect(route('publishers.index'));

        $publisher = $this->withTenant($college, fn () => Publisher::query()->where('name', 'Addison-Wesley')->firstOrFail());
        $this->assertSame($college->id, $publisher->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $publisher->created_by);
        $this->assertSame('orders@example.test', $publisher->email);
        $this->assertSame('https://www.example.test', $publisher->website);
        $this->assertSame('addison-wesley', $publisher->name_normalized);

        $this->asCollege($college, $user)->get(route('publishers.index'))->assertOk()->assertSee('Addison-Wesley')->assertSee('orders@example.test');
        $this->asCollege($college, $user)->get(route('publishers.edit', $publisher))->assertOk()->assertSee('Addison-Wesley');

        $this->asCollege($college, $user)
            ->put(route('publishers.update', $publisher), $this->publisherPayload(['name' => 'Pearson', 'email' => '', 'website' => '', 'status' => Publisher::STATUS_INACTIVE]))
            ->assertRedirect(route('publishers.index'));

        $publisher->refresh();
        $this->assertSame('Pearson', $publisher->name);
        $this->assertNull($publisher->email, 'An emptied optional field is stored as NULL.');
        $this->assertNull($publisher->website);
        $this->assertSame(Publisher::STATUS_INACTIVE, $publisher->status);
        $this->assertSame($user->id, $publisher->updated_by);

        $this->asCollege($college, $user)->delete(route('publishers.destroy', $publisher))->assertRedirect(route('publishers.index'));
        $this->assertSoftDeleted('publishers', ['id' => $publisher->id]);
    }

    public function test_duplicate_publisher_names_are_rejected_within_a_college_but_allowed_across_colleges(): void
    {
        $college = $this->makeCollege('LPUB2');
        $other = $this->makeCollege('LPUB2X');
        $user = $this->makeUserWithPermissions($college, ['publishers.view', 'publishers.create', 'publishers.update']);
        $this->makePublisher($college, ['name' => 'Oxford University Press']);
        $this->makePublisher($other, ['name' => 'Cambridge University Press']);
        $mine = $this->makePublisher($college, ['name' => 'Springer']);

        $this->asCollege($college, $user)
            ->post(route('publishers.store'), $this->publisherPayload(['name' => 'oxford  university press']))
            ->assertSessionHasErrors('name');

        $this->asCollege($college, $user)
            ->post(route('publishers.store'), $this->publisherPayload(['name' => 'Cambridge University Press']))
            ->assertSessionHasNoErrors();

        $this->asCollege($college, $user)
            ->put(route('publishers.update', $mine), $this->publisherPayload(['name' => 'OXFORD UNIVERSITY PRESS']))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $this->withTenant($college, fn () => Publisher::query()->where('name_normalized', 'oxford university press')->count()));
    }

    public function test_publisher_validation_rejects_bad_contact_details(): void
    {
        $college = $this->makeCollege('LPUB3');
        $user = $this->makeUserWithPermissions($college, ['publishers.view', 'publishers.create']);

        $this->asCollege($college, $user)->post(route('publishers.store'), [])->assertSessionHasErrors(['name', 'status']);
        $this->asCollege($college, $user)
            ->post(route('publishers.store'), $this->publisherPayload(['email' => 'not-an-email', 'website' => 'not a url', 'status' => 'closed']))
            ->assertSessionHasErrors(['email', 'website', 'status']);

        $this->assertSame(0, $this->withTenant($college, fn () => Publisher::query()->count()));
    }

    public function test_a_publisher_referenced_by_books_cannot_be_deleted(): void
    {
        $college = $this->makeCollege('LPUB4');
        $user = $this->makeUserWithPermissions($college, ['publishers.view', 'publishers.delete']);
        $publisher = $this->makePublisher($college);
        $this->makeBook($college, ['publisher_id' => $publisher->id]);

        $this->asCollege($college, $user)
            ->from(route('publishers.index'))
            ->delete(route('publishers.destroy', $publisher))
            ->assertRedirect(route('publishers.index'))
            ->assertSessionHasErrors('publisher');

        $this->assertDatabaseHas('publishers', ['id' => $publisher->id, 'deleted_at' => null]);
    }

    public function test_publishers_are_isolated_per_college_and_permission_gated(): void
    {
        $college = $this->makeCollege('LPUB5');
        $other = $this->makeCollege('LPUB5X');
        $user = $this->makeUserWithPermissions($college, ['publishers.view', 'publishers.update', 'publishers.delete']);
        $viewer = $this->makeUserWithPermissions($college, ['publishers.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $mine = $this->makePublisher($college, ['name' => 'Mine Press']);
        $theirs = $this->makePublisher($other, ['name' => 'Theirs Press']);

        $this->asCollege($college, $user)->get(route('publishers.index'))->assertOk()->assertSee('Mine Press')->assertDontSee('Theirs Press');
        $this->asCollege($college, $user)->get(route('publishers.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('publishers.update', $theirs), $this->publisherPayload())->assertNotFound();
        $this->asCollege($college, $user)->delete(route('publishers.destroy', $theirs))->assertNotFound();
        $this->assertDatabaseHas('publishers', ['id' => $theirs->id, 'name' => 'Theirs Press', 'deleted_at' => null]);

        $this->asCollege($college, $nobody)->get(route('publishers.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('publishers.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('publishers.store'), $this->publisherPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('publishers.edit', $mine))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('publishers.update', $mine), $this->publisherPayload())->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('publishers.destroy', $mine))->assertForbidden();
    }

    public function test_publisher_changes_are_audited(): void
    {
        $college = $this->makeCollege('LPUB6');
        $user = $this->makeUserWithPermissions($college, ['publishers.view', 'publishers.create', 'publishers.update', 'publishers.delete']);

        $this->asCollege($college, $user)->post(route('publishers.store'), $this->publisherPayload())->assertRedirect();
        $publisher = $this->withTenant($college, fn () => Publisher::query()->where('name', 'Addison-Wesley')->firstOrFail());
        $this->asCollege($college, $user)->put(route('publishers.update', $publisher), $this->publisherPayload(['name' => 'Renamed Press']))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('publishers.destroy', $publisher))->assertRedirect();

        $actions = AuditLog::query()->where('subject_type', Publisher::class)->where('subject_id', $publisher->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['publishers.created', 'publishers.updated', 'publishers.deleted'], $actions);

        $updated = AuditLog::query()->where('action', 'publishers.updated')->where('subject_id', $publisher->id)->firstOrFail();
        $this->assertSame('Addison-Wesley', $updated->old_values['name']);
        $this->assertSame('Renamed Press', $updated->new_values['name']);
        $this->assertSame($college->id, $updated->college_id);
    }

    public function test_the_authors_and_publishers_screens_link_to_each_other_when_permitted(): void
    {
        $college = $this->makeCollege('LAP1');
        $both = $this->makeUserWithPermissions($college, ['authors.view', 'publishers.view']);
        $authorsOnly = $this->makeUserWithPermissions($college, ['authors.view']);

        $this->asCollege($college, $both)->get(route('authors.index'))->assertOk()->assertSee(route('publishers.index'));
        $this->asCollege($college, $both)->get(route('publishers.index'))->assertOk()->assertSee(route('authors.index'));

        $this->asCollege($college, $authorsOnly)->get(route('authors.index'))->assertOk()->assertDontSee(route('publishers.index'));
        $this->asCollege($college, $authorsOnly)->get(route('publishers.index'))->assertForbidden();
    }
}
