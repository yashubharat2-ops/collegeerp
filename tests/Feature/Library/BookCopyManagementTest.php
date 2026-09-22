<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Services\BookCopyService;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Library Management — Book Copies.
 *
 * A copy is a physical item of an existing book. These tests cover CRUD,
 * per-college accession/barcode uniqueness, per-title copy numbers, the
 * refusal to invent an "issued" status from the copy form, deletion rules,
 * tenant isolation, RBAC, audit and escaped output.
 */
class BookCopyManagementTest extends TestCase
{
    use LibraryTestHelpers;

    private const MANAGE = ['book_copies.view', 'book_copies.create', 'book_copies.update', 'book_copies.delete', 'books.view'];

    public function test_a_copy_can_be_created_for_a_book_of_the_active_college(): void
    {
        $college = $this->makeCollege('LCP1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $book = $this->makeBook($college, ['title' => 'Data Structures']);

        $response = $this->asCollege($college, $user)->post(route('book-copies.store'), [
            'book_id' => $book->id,
            'accession_number' => 'acc-0001',
            'barcode' => 'bc-100',
            'copy_number' => 1,
            'location' => 'Stack A / Shelf 3',
            'condition' => BookCopy::CONDITION_GOOD,
            'status' => BookCopy::STATUS_AVAILABLE,
            'acquired_on' => now()->subMonth()->toDateString(),
            'remarks' => 'Donated.',
            'college_id' => 999,
            'created_by' => 999,
        ]);

        $copy = $this->withTenant($college, fn () => BookCopy::query()->where('accession_number', 'ACC-0001')->firstOrFail());

        $response->assertRedirect(route('book-copies.show', $copy))->assertSessionHasNoErrors();

        $this->assertSame($college->id, $copy->college_id, 'college_id must come from the tenant, never the payload.');
        $this->assertSame($user->id, $copy->created_by);
        $this->assertSame($user->id, $copy->updated_by);
        $this->assertSame($book->id, $copy->book_id);
        $this->assertSame('ACC-0001', $copy->accession_number);
        $this->assertSame('BC-100', $copy->barcode);
        $this->assertSame(1, $copy->copy_number);
        $this->assertSame('Stack A / Shelf 3', $copy->location);
        $this->assertSame(BookCopy::STATUS_AVAILABLE, $copy->status);
        $this->assertNull($copy->getAttribute('title'), 'A copy must not store the book title.');

        $this->asCollege($college, $user)
            ->get(route('book-copies.show', $copy))
            ->assertOk()
            ->assertSee('ACC-0001')
            ->assertSee('Data Structures')
            ->assertSee('BC-100')
            ->assertSee('Donated.');
    }

    public function test_accession_and_barcode_are_unique_within_the_college_and_reusable_after_soft_delete(): void
    {
        $college = $this->makeCollege('LCP2');
        $other = $this->makeCollege('LCP2B');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $otherUser = $this->makeUserWithPermissions($other, self::MANAGE);
        $book = $this->makeBook($college);
        $otherBook = $this->makeBook($other);

        $this->makeBookCopy($college, $book, [
            'accession_number' => 'ACC-DUP',
            'barcode' => 'BC-DUP',
            'copy_number' => 1,
        ]);

        $this->asCollege($college, $user)
            ->post(route('book-copies.store'), $this->copyPayload($book, [
                'accession_number' => 'acc-dup',
                'barcode' => 'other',
                'copy_number' => 2,
            ]))
            ->assertSessionHasErrors('accession_number');

        $this->asCollege($college, $user)
            ->post(route('book-copies.store'), $this->copyPayload($book, [
                'accession_number' => 'ACC-NEW',
                'barcode' => 'bc-dup',
                'copy_number' => 2,
            ]))
            ->assertSessionHasErrors('barcode');

        $this->asCollege($college, $user)
            ->post(route('book-copies.store'), $this->copyPayload($book, [
                'accession_number' => 'ACC-NUM',
                'barcode' => 'BC-NUM',
                'copy_number' => 1,
            ]))
            ->assertSessionHasErrors('copy_number');

        // Another college may use the same accession and barcode.
        $this->asCollege($other, $otherUser)
            ->post(route('book-copies.store'), $this->copyPayload($otherBook, [
                'accession_number' => 'ACC-DUP',
                'barcode' => 'BC-DUP',
                'copy_number' => 1,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $existing = $this->withTenant($college, fn () => BookCopy::query()->where('accession_number', 'ACC-DUP')->firstOrFail());
        $this->asCollege($college, $user)->delete(route('book-copies.destroy', $existing))->assertRedirect();

        $this->asCollege($college, $user)
            ->post(route('book-copies.store'), $this->copyPayload($book, [
                'accession_number' => 'ACC-DUP',
                'barcode' => 'BC-DUP',
                'copy_number' => 1,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, BookCopy::withTrashed()->where('college_id', $college->id)->where('accession_number', 'ACC-DUP')->count());
    }

    public function test_a_copy_cannot_be_created_as_issued_or_for_another_colleges_book(): void
    {
        $college = $this->makeCollege('LCP3');
        $other = $this->makeCollege('LCP3B');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $book = $this->makeBook($college);
        $foreign = $this->makeBook($other);

        $this->asCollege($college, $user)
            ->post(route('book-copies.store'), $this->copyPayload($book, ['status' => BookCopy::STATUS_ISSUED]))
            ->assertSessionHasErrors('status');

        $this->asCollege($college, $user)
            ->post(route('book-copies.store'), $this->copyPayload($book, ['book_id' => $foreign->id]))
            ->assertSessionHasErrors('book_id');

        $this->assertSame(0, BookCopy::withTrashed()->where('book_id', $foreign->id)->count());

        // The service rejects a forged book even if the form request is bypassed.
        $this->withTenant($college, function () use ($college, $user, $foreign): void {
            try {
                app(BookCopyService::class)->create($college, [
                    'book_id' => $foreign->id,
                    'accession_number' => 'ACC-FORGED',
                    'copy_number' => 1,
                    'condition' => BookCopy::CONDITION_GOOD,
                    'status' => BookCopy::STATUS_AVAILABLE,
                ], $user);
                $this->fail('A cross-tenant book id must be rejected by the service.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('book_id', $e->errors());
            }
        });
    }

    public function test_an_issued_copy_cannot_have_its_status_edited_and_its_title_is_frozen(): void
    {
        $college = $this->makeCollege('LCP4');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $book = $this->makeBook($college);
        $otherBook = $this->makeBook($college, ['title' => 'Other Title']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-ISS', 'status' => BookCopy::STATUS_AVAILABLE]);
        $this->makeIssuedTransaction($college, $copy);

        $this->from(route('book-copies.edit', $copy))
            ->asCollege($college, $user)
            ->put(route('book-copies.update', $copy), [
                'accession_number' => 'ACC-ISS',
                'copy_number' => 1,
                'condition' => BookCopy::CONDITION_FAIR,
                'status' => BookCopy::STATUS_AVAILABLE,
                'book_id' => $otherBook->id,
                'location' => 'Reserve desk',
            ])
            ->assertSessionHasErrors('status');

        $copy->refresh();
        $this->assertSame(BookCopy::STATUS_ISSUED, $copy->status);
        $this->assertSame($book->id, $copy->book_id);
        $this->assertNull($copy->location, 'A rejected update must not partially apply.');

        $this->asCollege($college, $user)
            ->put(route('book-copies.update', $copy), [
                'accession_number' => 'ACC-ISS',
                'copy_number' => 1,
                'condition' => BookCopy::CONDITION_FAIR,
                'location' => 'Reserve desk',
                'book_id' => $otherBook->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('book-copies.show', $copy));

        $copy->refresh();
        $this->assertSame('Reserve desk', $copy->location);
        $this->assertSame(BookCopy::CONDITION_FAIR, $copy->condition);
        $this->assertSame($book->id, $copy->book_id, 'book_id from the request must be ignored.');
        $this->assertSame(BookCopy::STATUS_ISSUED, $copy->status);
    }

    public function test_a_copy_with_circulation_history_cannot_be_deleted_and_a_title_with_copies_cannot_either(): void
    {
        $college = $this->makeCollege('LCP5');
        $user = $this->makeUserWithPermissions($college, [...self::MANAGE, 'books.delete']);
        $book = $this->makeBook($college);
        $clean = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-CLEAN', 'copy_number' => 1]);
        $used = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-USED', 'copy_number' => 2]);
        $this->makeIssuedTransaction($college, $used);

        $this->from(route('book-copies.show', $used))
            ->asCollege($college, $user)
            ->delete(route('book-copies.destroy', $used))
            ->assertRedirect(route('book-copies.show', $used))
            ->assertSessionHasErrors('copy');

        $this->assertNull($used->fresh()->deleted_at);
        $this->assertDatabaseHas('library_transactions', ['book_copy_id' => $used->id]);

        $this->from(route('books.show', $book))
            ->asCollege($college, $user)
            ->delete(route('books.destroy', $book))
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasErrors('book');

        $this->assertNull($book->fresh()->deleted_at);

        $this->asCollege($college, $user)->delete(route('book-copies.destroy', $clean))->assertRedirect(route('book-copies.index'));
        $this->assertSoftDeleted('book_copies', ['id' => $clean->id]);

        $this->from(route('books.show', $book))
            ->asCollege($college, $user)
            ->delete(route('books.destroy', $book))
            ->assertSessionHasErrors('book');

        $this->assertNotNull(Book::query()->find($book->id));
    }

    public function test_copies_are_tenant_isolated_and_permission_gated(): void
    {
        $college = $this->makeCollege('LCP6');
        $other = $this->makeCollege('LCP6B');
        $viewer = $this->makeUserWithPermissions($college, ['book_copies.view']);
        $stranger = $this->makeUserWithPermissions($college, ['books.view']);
        $otherUser = $this->makeUserWithPermissions($other, self::MANAGE);
        $book = $this->makeBook($college, ['title' => '<script>alert(1)</script>']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-TENANT', 'barcode' => 'BC-TENANT']);
        $hidden = $this->makeBookCopy($other, null, ['accession_number' => 'ACC-HIDDEN']);

        $this->asCollege($college, $viewer)
            ->get(route('book-copies.index'))
            ->assertOk()
            ->assertSee('ACC-TENANT')
            ->assertDontSee('ACC-HIDDEN')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->asCollege($college, $viewer)->get(route('book-copies.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('book-copies.store'), $this->copyPayload($book))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('book-copies.destroy', $copy))->assertForbidden();
        $this->asCollege($college, $stranger)->get(route('book-copies.index'))->assertForbidden();

        $this->asCollege($other, $otherUser)->get(route('book-copies.show', $copy))->assertNotFound();
        $this->asCollege($other, $otherUser)->put(route('book-copies.update', $copy), [
            'accession_number' => 'STOLEN',
            'copy_number' => 9,
            'condition' => BookCopy::CONDITION_POOR,
            'status' => BookCopy::STATUS_WITHDRAWN,
        ])->assertNotFound();

        $this->assertSame('ACC-TENANT', $copy->fresh()->accession_number);
    }

    public function test_the_index_filters_and_orders_copies_deterministically(): void
    {
        $college = $this->makeCollege('LCP7');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $book = $this->makeBook($college, ['title' => 'Algorithms']);
        $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-Z', 'status' => BookCopy::STATUS_DAMAGED, 'location' => 'Basement']);
        $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-A', 'copy_number' => 2, 'status' => BookCopy::STATUS_AVAILABLE, 'location' => 'Reading room']);

        $this->asCollege($college, $user)
            ->get(route('book-copies.index'))
            ->assertOk()
            ->assertSeeInOrder(['ACC-A', 'ACC-Z']);

        $this->asCollege($college, $user)
            ->get(route('book-copies.index', ['status' => BookCopy::STATUS_AVAILABLE, 'search' => 'Reading']))
            ->assertOk()
            ->assertSee('ACC-A')
            ->assertDontSee('ACC-Z');
    }

    public function test_copy_mutations_are_audited(): void
    {
        $college = $this->makeCollege('LCP8');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $book = $this->makeBook($college);

        $this->asCollege($college, $user)->post(route('book-copies.store'), $this->copyPayload($book, [
            'accession_number' => 'ACC-AUD',
            'location' => 'Desk',
        ]))->assertRedirect();

        $copy = $this->withTenant($college, fn () => BookCopy::query()->where('accession_number', 'ACC-AUD')->firstOrFail());

        $this->asCollege($college, $user)->put(route('book-copies.update', $copy), $this->copyPayload($book, [
            'accession_number' => 'ACC-AUD',
            'location' => 'Stacks',
        ]))->assertRedirect();

        $this->asCollege($college, $user)->delete(route('book-copies.destroy', $copy))->assertRedirect();

        $actions = AuditLog::query()->where('subject_type', BookCopy::class)->where('subject_id', $copy->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['book_copies.created', 'book_copies.updated', 'book_copies.deleted'], $actions);

        $created = AuditLog::query()->where('action', 'book_copies.created')->where('subject_id', $copy->id)->firstOrFail();
        $this->assertSame($college->id, $created->college_id);
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame('ACC-AUD', $created->new_values['accession_number']);
        $this->assertSame($book->id, $created->new_values['book_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function copyPayload(Book $book, array $overrides = []): array
    {
        return array_merge([
            'book_id' => $book->id,
            'accession_number' => 'ACC-'.strtoupper(substr(uniqid(), -6)),
            'barcode' => null,
            'copy_number' => 1,
            'location' => null,
            'condition' => BookCopy::CONDITION_GOOD,
            'status' => BookCopy::STATUS_AVAILABLE,
            'acquired_on' => null,
            'remarks' => null,
        ], $overrides);
    }
}
