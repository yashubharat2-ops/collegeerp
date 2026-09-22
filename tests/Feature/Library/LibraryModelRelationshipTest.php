<?php

namespace Tests\Feature\Library;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\College;
use App\Models\Publisher;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

/**
 * Library Management — model relationships and scoping.
 *
 * Verifies the Eloquent wiring between the Phase 1 masters (types and round
 * trips), the many-to-many author link, the tenant scope on every model, the
 * attribute normalisation (code / ISBN / name_normalized). Circulation hangs
 * off BookCopy, not off quantity columns on the book master.
 */
class LibraryModelRelationshipTest extends TestCase
{
    use LibraryTestHelpers;

    public function test_relationships_are_typed_and_round_trip(): void
    {
        $college = $this->makeCollege('LREL1');
        $user = User::create(['name' => 'Cataloguer', 'email' => 'cataloguer-lrel1@example.test', 'password' => 'password', 'is_active' => true]);
        $category = $this->makeBookCategory($college);
        $publisher = $this->makePublisher($college);
        $first = $this->makeAuthor($college, ['name' => 'First Author']);
        $second = $this->makeAuthor($college, ['name' => 'Second Author']);
        $book = $this->makeBook($college, [
            'book_category_id' => $category->id,
            'publisher_id' => $publisher->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], [$second, $first]);

        $this->withTenant($college, function () use ($book, $category, $publisher, $first, $second, $user, $college): void {
            $book = Book::query()->findOrFail($book->id);

            $this->assertInstanceOf(BelongsTo::class, $book->category());
            $this->assertInstanceOf(BelongsTo::class, $book->publisher());
            $this->assertInstanceOf(BelongsToMany::class, $book->authors());
            $this->assertInstanceOf(BelongsTo::class, $book->college());
            $this->assertInstanceOf(BelongsTo::class, $book->creator());
            $this->assertInstanceOf(BelongsTo::class, $book->updater());
            $this->assertInstanceOf(HasMany::class, $category->books());
            $this->assertInstanceOf(HasMany::class, $publisher->books());
            $this->assertInstanceOf(BelongsToMany::class, $first->books());

            $this->assertTrue($book->category->is($category));
            $this->assertTrue($book->publisher->is($publisher));
            $this->assertTrue($book->college->is($college));
            $this->assertTrue($book->creator->is($user));
            $this->assertTrue($book->updater->is($user));

            // Authors come back in credited order, with the pivot exposed.
            $this->assertSame([$second->id, $first->id], $book->authors->pluck('id')->all());
            $this->assertSame([1, 2], $book->authors->pluck('pivot.sort_order')->all());
            $this->assertSame($college->id, (int) $book->authors->first()->pivot->college_id);
            $this->assertSame('Second Author, First Author', $book->authorNames());

            $this->assertTrue($category->books->contains($book));
            $this->assertTrue($publisher->books->contains($book));
            $this->assertTrue($first->books->contains($book));
            $this->assertTrue($second->books->contains($book));
        });
    }

    public function test_every_library_model_is_tenant_scoped(): void
    {
        $college = $this->makeCollege('LREL2');
        $other = $this->makeCollege('LREL2X');
        $category = $this->makeBookCategory($college);
        $author = $this->makeAuthor($college);
        $publisher = $this->makePublisher($college);
        $book = $this->makeBook($college, ['book_category_id' => $category->id, 'publisher_id' => $publisher->id], [$author]);
        $this->makeBook($other);
        $this->makeAuthor($other);
        $this->makePublisher($other);

        // Without a tenant nothing is visible…
        $this->assertSame(0, Book::query()->count());
        $this->assertSame(0, BookCategory::query()->count());
        $this->assertSame(0, Author::query()->count());
        $this->assertSame(0, Publisher::query()->count());

        // …with one, only that college's rows are.
        $this->withTenant($college, function () use ($book, $category, $author, $publisher): void {
            $this->assertSame([$book->id], Book::query()->pluck('id')->all());
            $this->assertSame([$category->id], BookCategory::query()->pluck('id')->all());
            $this->assertSame([$author->id], Author::query()->pluck('id')->all());
            $this->assertSame([$publisher->id], Publisher::query()->pluck('id')->all());
        });

        $this->withTenant($other, function () use ($book): void {
            $this->assertNull(Book::query()->find($book->id));
            $this->assertSame(1, Book::query()->count());
            $this->assertSame(1, Author::query()->count());
            $this->assertSame(1, Publisher::query()->count());
            // The other college's book has its own auto-created category.
            $this->assertSame(1, BookCategory::query()->count());
        });
    }

    public function test_college_id_is_stamped_from_the_tenant_context_when_omitted(): void
    {
        $college = $this->makeCollege('LREL3');
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            $category = BookCategory::create(['name' => 'Stamped', 'code' => 'STAMP', 'status' => 'active']);
            $author = Author::create(['name' => 'Stamped Author', 'status' => 'active']);
            $publisher = Publisher::create(['name' => 'Stamped Press', 'status' => 'active']);
            $book = Book::create(['title' => 'Stamped Book', 'code' => 'BK-STAMP', 'book_category_id' => $category->id, 'status' => 'active']);
        } finally {
            $context->clear();
        }

        foreach ([$category, $author, $publisher, $book] as $model) {
            $this->assertSame($college->id, (int) $model->college_id);
        }
    }

    public function test_attributes_are_normalized_on_save(): void
    {
        $college = $this->makeCollege('LREL4');
        $category = $this->makeBookCategory($college, ['code' => ' ref ']);
        $author = $this->makeAuthor($college, ['name' => '  Ursula   K.  Le Guin ']);
        $publisher = $this->makePublisher($college, ['name' => 'HarperCollins  Publishers']);
        $book = $this->makeBook($college, ['code' => 'bk-001', 'isbn' => ' 978-0-13-468599-1 ', 'book_category_id' => $category->id]);

        $this->assertSame('REF', $category->code);
        $this->assertSame('ursula k. le guin', $author->name_normalized);
        $this->assertSame('harpercollins publishers', $publisher->name_normalized);
        $this->assertSame('BK-001', $book->code);
        $this->assertSame('9780134685991', $book->isbn);

        $book->isbn = '';
        $book->save();
        $this->assertNull($book->refresh()->isbn, 'An empty ISBN is stored as NULL so it never collides.');

        $this->assertTrue($book->isActive());
        $this->assertTrue($category->isActive());
        $this->assertTrue($author->isActive());
        $this->assertTrue($publisher->isActive());
        $this->assertSame(['active', 'inactive'], Book::STATUSES);
    }

    public function test_soft_deleting_a_book_hides_it_from_its_masters_and_the_pivot_survives_for_audit(): void
    {
        $college = $this->makeCollege('LREL5');
        $category = $this->makeBookCategory($college);
        $author = $this->makeAuthor($college);
        $book = $this->makeBook($college, ['book_category_id' => $category->id], [$author]);

        $this->withTenant($college, function () use ($book, $category, $author): void {
            $book->delete();

            $this->assertSoftDeleted('books', ['id' => $book->id]);
            $this->assertSame(0, $category->books()->count());
            $this->assertSame(0, $author->books()->count());
            $this->assertSame(1, Book::withTrashed()->whereKey($book->id)->count());
            $this->assertDatabaseHas('author_book', ['book_id' => $book->id, 'author_id' => $author->id]);
        });
    }

    public function test_the_book_master_points_at_copies_without_duplicating_stock(): void
    {
        $this->assertTrue(method_exists(Book::class, 'copies'));
        $this->assertInstanceOf(HasMany::class, (new Book())->copies());

        foreach (['issues', 'members', 'fines', 'renewals'] as $method) {
            $this->assertFalse(method_exists(Book::class, $method), "Book::{$method}() would duplicate circulation that hangs off copies.");
        }

        $this->assertTrue(\Schema::hasTable('book_copies'));
        $this->assertTrue(\Schema::hasTable('library_members'));
        $this->assertTrue(\Schema::hasTable('library_transactions'));
        $this->assertTrue(\Schema::hasTable('library_renewals'));
        $this->assertFalse(\Schema::hasTable('book_issues'));
        $this->assertFalse(\Schema::hasColumn('books', 'quantity'));
        $this->assertFalse(\Schema::hasColumn('books', 'available_copies'));
    }

    public function test_deleting_a_college_cascades_to_its_library_masters(): void
    {
        $college = $this->makeCollege('LREL6');
        $author = $this->makeAuthor($college);
        $book = $this->makeBook($college, [], [$author]);

        // Colleges are soft-deleted in the ERP; a hard delete exercises the
        // database-level ON DELETE CASCADE declared by the migrations.
        \DB::table('colleges')->where('id', $college->id)->delete();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('authors', ['id' => $author->id]);
        $this->assertDatabaseMissing('author_book', ['book_id' => $book->id]);
    }
}
