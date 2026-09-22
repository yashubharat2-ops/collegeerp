<?php

namespace Tests\Feature\Library;

use App\Models\AuditLog;
use App\Models\Book;
use Tests\TestCase;

/**
 * Library Management — Books (the bibliographic master).
 *
 * Covers CRUD through HTTP, the per-college uniqueness of `code` and `isbn`
 * (ISBN compared in normalized form), contextual FK validation (category /
 * publisher / authors must belong to the active college), author linking with
 * order, tenant isolation, RBAC and audit logging. Physical copies are out of
 * scope for Phase 1 and nothing here touches them.
 */
class BookManagementTest extends TestCase
{
    use LibraryTestHelpers;

    private const MANAGE = ['books.view', 'books.create', 'books.update', 'books.delete'];

    public function test_a_book_can_be_created_with_category_publisher_and_authors(): void
    {
        $college = $this->makeCollege('LBK1');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $publisher = $this->makePublisher($college, ['name' => 'MIT Press']);
        $cormen = $this->makeAuthor($college, ['name' => 'Thomas H. Cormen']);
        $leiserson = $this->makeAuthor($college, ['name' => 'Charles E. Leiserson']);

        $response = $this->asCollege($college, $user)->post(route('books.store'), $this->bookPayload($category, [
            'code' => 'bk-algo-001',
            'publisher_id' => $publisher->id,
            'author_ids' => [$leiserson->id, $cormen->id],
            // Forged tenant/audit columns must be ignored.
            'college_id' => 999,
            'created_by' => 999,
        ]));

        $book = $this->withTenant($college, fn () => Book::query()->where('code', 'BK-ALGO-001')->with('authors')->firstOrFail());

        $response->assertRedirect(route('books.show', $book))->assertSessionHasNoErrors();

        $this->assertSame($college->id, $book->college_id, 'college_id must come from the tenant context, never the payload.');
        $this->assertSame($user->id, $book->created_by);
        $this->assertSame($user->id, $book->updated_by);
        $this->assertSame('Introduction to Algorithms', $book->title);
        $this->assertSame('BK-ALGO-001', $book->code, 'Codes are stored upper-cased.');
        $this->assertSame('9780262033848', $book->isbn, 'ISBNs are stored normalized (no hyphens).');
        $this->assertSame($category->id, $book->book_category_id);
        $this->assertSame($publisher->id, $book->publisher_id);
        $this->assertSame('3rd ed.', $book->edition);
        $this->assertSame(2009, $book->publication_year);
        $this->assertSame('English', $book->language);
        $this->assertSame(Book::STATUS_ACTIVE, $book->status);

        // Authors are linked in the submitted order, with the tenant stamped on the pivot.
        $this->assertSame([$leiserson->id, $cormen->id], $book->authors->pluck('id')->all());
        $this->assertSame([1, 2], $book->authors->pluck('pivot.sort_order')->all());
        $this->assertDatabaseHas('author_book', ['book_id' => $book->id, 'author_id' => $cormen->id, 'college_id' => $college->id, 'sort_order' => 2]);

        $this->asCollege($college, $user)
            ->get(route('books.show', $book))
            ->assertOk()
            ->assertSee('Introduction to Algorithms')
            ->assertSee('9780262033848')
            ->assertSee('MIT Press')
            ->assertSee('Thomas H. Cormen')
            ->assertSee('Charles E. Leiserson');
    }

    public function test_a_book_can_be_created_without_isbn_publisher_or_authors(): void
    {
        $college = $this->makeCollege('LBK2');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, [
                'code' => 'BK-THESIS-1',
                'isbn' => '',
                'publisher_id' => '',
                'author_ids' => [],
                'edition' => '',
                'publication_year' => '',
                'language' => '',
                'description' => '',
            ]))
            ->assertSessionHasNoErrors();

        $book = $this->withTenant($college, fn () => Book::query()->where('code', 'BK-THESIS-1')->firstOrFail());

        $this->assertNull($book->isbn);
        $this->assertNull($book->publisher_id);
        $this->assertNull($book->edition);
        $this->assertNull($book->publication_year);
        $this->assertNull($book->language);
        $this->assertSame(0, $book->authors()->count());

        // Two ISBN-less books are allowed: NULL never collides.
        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-THESIS-2', 'isbn' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => Book::query()->whereNull('isbn')->count()));
    }

    public function test_the_index_lists_filters_and_searches_books(): void
    {
        $college = $this->makeCollege('LBK3');
        $user = $this->makeUserWithPermissions($college, ['books.view']);
        $fiction = $this->makeBookCategory($college, ['name' => 'Fiction', 'code' => 'FIC']);
        $science = $this->makeBookCategory($college, ['name' => 'Science', 'code' => 'SCI']);
        $publisher = $this->makePublisher($college, ['name' => 'Penguin']);
        $author = $this->makeAuthor($college, ['name' => 'Ursula K. Le Guin']);
        $this->makeBook($college, ['title' => 'The Dispossessed', 'code' => 'BK-DISP', 'book_category_id' => $fiction->id, 'publisher_id' => $publisher->id], [$author]);
        $this->makeBook($college, ['title' => 'Cosmos', 'code' => 'BK-COSMOS', 'book_category_id' => $science->id, 'isbn' => '978-0-345-33135-9', 'status' => Book::STATUS_INACTIVE]);

        $this->asCollege($college, $user)->get(route('books.index'))->assertOk()->assertSee('The Dispossessed')->assertSee('Cosmos');
        $this->asCollege($college, $user)->get(route('books.index', ['book_category_id' => $fiction->id]))->assertOk()->assertSee('The Dispossessed')->assertDontSee('Cosmos');
        $this->asCollege($college, $user)->get(route('books.index', ['author_id' => $author->id]))->assertOk()->assertSee('The Dispossessed')->assertDontSee('Cosmos');
        $this->asCollege($college, $user)->get(route('books.index', ['publisher_id' => $publisher->id]))->assertOk()->assertSee('The Dispossessed')->assertDontSee('Cosmos');
        $this->asCollege($college, $user)->get(route('books.index', ['status' => 'inactive']))->assertOk()->assertSee('Cosmos')->assertDontSee('The Dispossessed');
        $this->asCollege($college, $user)->get(route('books.index', ['search' => 'le guin']))->assertOk()->assertSee('The Dispossessed')->assertDontSee('Cosmos');
        // ISBN search tolerates the hyphenated form.
        $this->asCollege($college, $user)->get(route('books.index', ['search' => '978-0-345']))->assertOk()->assertSee('Cosmos')->assertDontSee('The Dispossessed');
    }

    public function test_a_duplicate_code_is_rejected_within_a_college_regardless_of_case(): void
    {
        $college = $this->makeCollege('LBK4');
        $other = $this->makeCollege('LBK4X');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $this->makeBook($college, ['code' => 'BK-DUP', 'book_category_id' => $category->id]);
        $this->makeBook($other, ['code' => 'BK-OTHER']);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'bk-dup', 'isbn' => '']))
            ->assertSessionHasErrors('code');

        // Another college's code is free here.
        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-OTHER', 'isbn' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => Book::query()->where('code', 'BK-DUP')->count()));
    }

    public function test_a_duplicate_isbn_is_rejected_within_a_college_even_when_formatted_differently(): void
    {
        $college = $this->makeCollege('LBK5');
        $other = $this->makeCollege('LBK5X');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $this->makeBook($college, ['code' => 'BK-1', 'isbn' => '9780262033848', 'book_category_id' => $category->id]);
        $this->makeBook($other, ['code' => 'BK-1', 'isbn' => '9780134685991']);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-2', 'isbn' => '978-0-262-03384-8']))
            ->assertSessionHasErrors('isbn');

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-2', 'isbn' => '978 0 262 03384 8']))
            ->assertSessionHasErrors('isbn');

        // The other college's ISBN is free here.
        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-2', 'isbn' => '978-0-13-468599-1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->withTenant($college, fn () => Book::query()->where('isbn', '9780262033848')->count()));
    }

    public function test_a_code_or_isbn_freed_by_a_soft_deleted_book_can_be_reused(): void
    {
        $college = $this->makeCollege('LBK6');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $book = $this->makeBook($college, ['code' => 'BK-REUSE', 'isbn' => '9780262033848', 'book_category_id' => $category->id]);

        $this->asCollege($college, $user)->delete(route('books.destroy', $book))->assertRedirect(route('books.index'));
        $this->assertSoftDeleted('books', ['id' => $book->id]);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-REUSE', 'isbn' => '978-0-262-03384-8']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withTenant($college, fn () => Book::withTrashed()->where('code', 'BK-REUSE')->count()));
    }

    public function test_validation_rejects_incomplete_or_malformed_payloads(): void
    {
        $college = $this->makeCollege('LBK7');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);

        $this->asCollege($college, $user)
            ->post(route('books.store'), [])
            ->assertSessionHasErrors(['title', 'code', 'book_category_id', 'status']);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, [
                'isbn' => '12345',
                'publication_year' => (int) now()->year + 5,
                'status' => 'lost',
            ]))
            ->assertSessionHasErrors(['isbn', 'publication_year', 'status']);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['isbn' => 'ABCDEFGHIJKLM']))
            ->assertSessionHasErrors('isbn');

        // Both ISBN-10 (with X check digit) and ISBN-13 forms are accepted.
        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-10', 'isbn' => '0-8044-2957-X']))
            ->assertSessionHasNoErrors();

        $this->assertSame('080442957X', $this->withTenant($college, fn () => Book::query()->where('code', 'BK-10')->value('isbn')));
        $this->assertSame(1, $this->withTenant($college, fn () => Book::query()->count()));
    }

    public function test_a_book_can_only_reference_a_category_publisher_and_authors_of_its_own_college(): void
    {
        $college = $this->makeCollege('LBK8');
        $other = $this->makeCollege('LBK8X');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $foreignCategory = $this->makeBookCategory($other);
        $foreignPublisher = $this->makePublisher($other);
        $foreignAuthor = $this->makeAuthor($other);
        $ownAuthor = $this->makeAuthor($college);

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($foreignCategory))
            ->assertSessionHasErrors('book_category_id');

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['publisher_id' => $foreignPublisher->id]))
            ->assertSessionHasErrors('publisher_id');

        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($category, ['author_ids' => [$ownAuthor->id, $foreignAuthor->id]]))
            ->assertSessionHasErrors('author_ids.1');

        // A soft-deleted category of the own college is not selectable either.
        $deleted = $this->makeBookCategory($college);
        $deleted->delete();
        $this->asCollege($college, $user)
            ->post(route('books.store'), $this->bookPayload($deleted))
            ->assertSessionHasErrors('book_category_id');

        $this->assertSame(0, $this->withTenant($college, fn () => Book::query()->count()));
        $this->assertSame(0, \DB::table('author_book')->count());
    }

    public function test_a_book_can_be_updated_including_its_authors(): void
    {
        $college = $this->makeCollege('LBK9');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $newCategory = $this->makeBookCategory($college);
        $publisher = $this->makePublisher($college);
        $a = $this->makeAuthor($college, ['name' => 'Author A']);
        $b = $this->makeAuthor($college, ['name' => 'Author B']);
        $c = $this->makeAuthor($college, ['name' => 'Author C']);
        $book = $this->makeBook($college, ['title' => 'Old Title', 'code' => 'BK-EDIT', 'book_category_id' => $category->id], [$a, $b]);

        $this->asCollege($college, $user)->get(route('books.edit', $book))->assertOk()->assertSee('Old Title')->assertSee('Author A');

        $this->asCollege($college, $user)
            ->put(route('books.update', $book), $this->bookPayload($newCategory, [
                'title' => 'New Title',
                'code' => 'BK-EDIT',
                'isbn' => '978-0-13-468599-1',
                'publisher_id' => $publisher->id,
                'author_ids' => [$c->id, $a->id],
                'publication_year' => 2018,
                'status' => Book::STATUS_INACTIVE,
            ]))
            ->assertRedirect(route('books.show', $book));

        $book->refresh()->load('authors');
        $this->assertSame('New Title', $book->title);
        $this->assertSame('9780134685991', $book->isbn);
        $this->assertSame($newCategory->id, $book->book_category_id);
        $this->assertSame($publisher->id, $book->publisher_id);
        $this->assertSame(2018, $book->publication_year);
        $this->assertSame(Book::STATUS_INACTIVE, $book->status);
        $this->assertSame($user->id, $book->updated_by);
        $this->assertSame([$c->id, $a->id], $book->authors->pluck('id')->all(), 'Authors are replaced and re-ordered.');
        $this->assertDatabaseMissing('author_book', ['book_id' => $book->id, 'author_id' => $b->id]);

        // Clearing the author list detaches everyone — including when the
        // browser omits the key entirely (an empty <select multiple>).
        $payload = $this->bookPayload($newCategory, ['code' => 'BK-EDIT', 'isbn' => '978-0-13-468599-1']);
        unset($payload['author_ids']);

        $this->asCollege($college, $user)
            ->put(route('books.update', $book), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $book->authors()->count());
    }

    public function test_updating_keeps_code_and_isbn_unique_among_other_active_books(): void
    {
        $college = $this->makeCollege('LBK10');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $this->makeBook($college, ['code' => 'BK-TAKEN', 'isbn' => '9780262033848', 'book_category_id' => $category->id]);
        $book = $this->makeBook($college, ['code' => 'BK-MINE', 'isbn' => '9780134685991', 'book_category_id' => $category->id]);

        // Its own identifiers are fine…
        $this->asCollege($college, $user)
            ->put(route('books.update', $book), $this->bookPayload($category, ['code' => 'BK-MINE', 'isbn' => '978-0-13-468599-1']))
            ->assertSessionHasNoErrors();

        // …another active book's are not.
        $this->asCollege($college, $user)
            ->put(route('books.update', $book), $this->bookPayload($category, ['code' => 'BK-TAKEN', 'isbn' => '978-0-13-468599-1']))
            ->assertSessionHasErrors('code');

        $this->asCollege($college, $user)
            ->put(route('books.update', $book), $this->bookPayload($category, ['code' => 'BK-MINE', 'isbn' => '978-0-262-03384-8']))
            ->assertSessionHasErrors('isbn');

        $this->assertSame('BK-MINE', $book->refresh()->code);
    }

    public function test_books_are_isolated_per_college(): void
    {
        $college = $this->makeCollege('LBK11');
        $other = $this->makeCollege('LBK11X');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $mine = $this->makeBook($college, ['title' => 'Mine Only', 'book_category_id' => $category->id]);
        $theirs = $this->makeBook($other, ['title' => 'Theirs Only']);

        $this->asCollege($college, $user)->get(route('books.index'))->assertOk()->assertSee('Mine Only')->assertDontSee('Theirs Only');
        $this->asCollege($college, $user)->get(route('books.show', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->get(route('books.edit', $theirs))->assertNotFound();
        $this->asCollege($college, $user)->put(route('books.update', $theirs), $this->bookPayload($category))->assertNotFound();
        $this->asCollege($college, $user)->delete(route('books.destroy', $theirs))->assertNotFound();

        $this->assertDatabaseHas('books', ['id' => $theirs->id, 'title' => 'Theirs Only', 'deleted_at' => null]);
        $this->assertDatabaseHas('books', ['id' => $mine->id, 'deleted_at' => null]);

        // The option lists on the form are tenant-scoped too.
        $foreignCategory = $this->makeBookCategory($other, ['name' => 'Foreign Category ZZ']);
        $foreignAuthor = $this->makeAuthor($other, ['name' => 'Foreign Author ZZ']);
        $this->asCollege($college, $user)
            ->get(route('books.create'))
            ->assertOk()
            ->assertSee($category->name)
            ->assertDontSee('Foreign Category ZZ')
            ->assertDontSee('Foreign Author ZZ');
    }

    public function test_every_action_is_permission_gated(): void
    {
        $college = $this->makeCollege('LBK12');
        $viewer = $this->makeUserWithPermissions($college, ['books.view']);
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $category = $this->makeBookCategory($college);
        $book = $this->makeBook($college, ['book_category_id' => $category->id]);

        $this->asCollege($college, $nobody)->get(route('books.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('books.show', $book))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('books.create'))->assertForbidden();

        $this->asCollege($college, $viewer)->get(route('books.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('books.show', $book))->assertOk();
        $this->asCollege($college, $viewer)->get(route('books.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('books.store'), $this->bookPayload($category))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('books.edit', $book))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('books.update', $book), $this->bookPayload($category))->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('books.destroy', $book))->assertForbidden();

        $this->assertSame(1, $this->withTenant($college, fn () => Book::query()->count()));
    }

    public function test_a_permission_granted_in_another_college_does_not_authorise_the_active_one(): void
    {
        $college = $this->makeCollege('LBK13');
        $other = $this->makeCollege('LBK13X');
        $user = $this->makeUserWithPermissions($other, self::MANAGE);
        $user->colleges()->attach($college->id, ['is_default' => false]);

        $this->asCollege($college, $user)->get(route('books.index'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('books.store'), $this->bookPayload($this->makeBookCategory($college)))->assertForbidden();

        $this->assertSame(0, $this->withTenant($college, fn () => Book::query()->count()));
    }

    public function test_a_super_admin_can_manage_books_in_the_active_college(): void
    {
        $college = $this->makeCollege('LBK14');
        $super = $this->makeSuperAdmin($college);
        $category = $this->makeBookCategory($college);

        $this->asCollege($college, $super)
            ->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-SUPER']))
            ->assertSessionHasNoErrors();

        $book = $this->withTenant($college, fn () => Book::query()->where('code', 'BK-SUPER')->firstOrFail());
        $this->assertSame($college->id, $book->college_id);

        $this->asCollege($college, $super)->get(route('books.show', $book))->assertOk();
    }

    public function test_book_changes_are_audited_with_their_authors(): void
    {
        $college = $this->makeCollege('LBK15');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $author = $this->makeAuthor($college);

        $this->asCollege($college, $user)->post(route('books.store'), $this->bookPayload($category, ['code' => 'BK-AUD', 'author_ids' => [$author->id]]))->assertRedirect();
        $book = $this->withTenant($college, fn () => Book::query()->where('code', 'BK-AUD')->firstOrFail());
        $this->asCollege($college, $user)->put(route('books.update', $book), $this->bookPayload($category, ['code' => 'BK-AUD', 'title' => 'Renamed', 'author_ids' => []]))->assertRedirect();
        $this->asCollege($college, $user)->delete(route('books.destroy', $book))->assertRedirect();

        $actions = AuditLog::query()->where('subject_type', Book::class)->where('subject_id', $book->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['books.created', 'books.updated', 'books.deleted'], $actions);

        $created = AuditLog::query()->where('action', 'books.created')->where('subject_id', $book->id)->firstOrFail();
        $this->assertSame([$author->id], $created->new_values['author_ids']);
        $this->assertSame('9780262033848', $created->new_values['isbn']);

        $updated = AuditLog::query()->where('action', 'books.updated')->where('subject_id', $book->id)->firstOrFail();
        $this->assertSame('Introduction to Algorithms', $updated->old_values['title']);
        $this->assertSame('Renamed', $updated->new_values['title']);
        $this->assertSame([$author->id], $updated->old_values['author_ids']);
        $this->assertSame([], $updated->new_values['author_ids']);
        $this->assertSame($college->id, $updated->college_id);
        $this->assertSame($user->id, $updated->user_id);

        $deleted = AuditLog::query()->where('action', 'books.deleted')->where('subject_id', $book->id)->firstOrFail();
        $this->assertSame('Renamed', $deleted->old_values['title']);
        $this->assertSame([], $deleted->new_values);
    }

    public function test_deleting_a_book_keeps_its_masters_intact(): void
    {
        $college = $this->makeCollege('LBK16');
        $user = $this->makeUserWithPermissions($college, self::MANAGE);
        $category = $this->makeBookCategory($college);
        $publisher = $this->makePublisher($college);
        $author = $this->makeAuthor($college);
        $book = $this->makeBook($college, ['book_category_id' => $category->id, 'publisher_id' => $publisher->id], [$author]);

        $this->asCollege($college, $user)->delete(route('books.destroy', $book))->assertRedirect(route('books.index'));

        $this->assertSoftDeleted('books', ['id' => $book->id]);
        $this->assertDatabaseHas('book_categories', ['id' => $category->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('publishers', ['id' => $publisher->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('authors', ['id' => $author->id, 'deleted_at' => null]);

        // A soft-deleted book no longer blocks deleting its (now unused) masters.
        $this->assertSame(0, $this->withTenant($college, fn () => $category->books()->count()));
        $this->assertSame(0, $this->withTenant($college, fn () => $author->books()->count()));
    }
}
