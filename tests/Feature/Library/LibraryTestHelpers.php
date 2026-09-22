<?php

namespace Tests\Feature\Library;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\College;
use App\Models\Publisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for the Library Management (Phase 1) tests.
 *
 * Reuses the project-wide fixtures (college, RBAC users, super admin) and adds
 * only what the Library module owns: categories, authors, publishers and books.
 * Fixtures are created directly (not through HTTP) so each test exercises one
 * behaviour, and are always stamped with an explicit college_id.
 */
trait LibraryTestHelpers
{
    use ExamAttendanceTestHelpers;

    /** Every Library permission slug seeded by DatabaseSeeder. */
    private const LIBRARY_PERMISSIONS = [
        'library_dashboard.view',
        'books.view', 'books.create', 'books.update', 'books.delete',
        'book_categories.view', 'book_categories.create', 'book_categories.update', 'book_categories.delete',
        'authors.view', 'authors.create', 'authors.update', 'authors.delete',
        'publishers.view', 'publishers.create', 'publishers.update', 'publishers.delete',
    ];

    /**
     * Run a callback with the tenant context bound to $college.
     *
     * Every Library model carries CollegeScope, which resolves to
     * `whereRaw('1 = 0')` when no tenant is active. Assertions that read these
     * models directly (outside an HTTP request) therefore have to pin the
     * tenant explicitly — inside a request the `tenant` middleware does it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBookCategory(College $college, array $overrides = []): BookCategory
    {
        return BookCategory::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Category '.Str::upper(Str::random(4)),
            'code' => 'CAT-'.Str::upper(Str::random(6)),
            'description' => 'Test category',
            'status' => BookCategory::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAuthor(College $college, array $overrides = []): Author
    {
        return Author::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Author '.Str::upper(Str::random(6)),
            'description' => 'Test author',
            'status' => Author::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePublisher(College $college, array $overrides = []): Publisher
    {
        return Publisher::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Publisher '.Str::upper(Str::random(6)),
            'description' => 'Test publisher',
            'status' => Publisher::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * A book with a category (created if not supplied) and optional authors.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<int, Author>  $authors
     */
    private function makeBook(College $college, array $overrides = [], array $authors = []): Book
    {
        $categoryId = $overrides['book_category_id'] ?? $this->makeBookCategory($college)->id;

        $book = Book::create(array_merge([
            'college_id' => $college->id,
            'book_category_id' => $categoryId,
            'title' => 'Book '.Str::upper(Str::random(6)),
            'code' => 'BK-'.Str::upper(Str::random(6)),
            'status' => Book::STATUS_ACTIVE,
        ], $overrides));

        if ($authors !== []) {
            $pivot = [];
            foreach (array_values($authors) as $index => $author) {
                $pivot[$author->id] = ['college_id' => $college->id, 'sort_order' => $index + 1];
            }
            $book->authors()->sync($pivot);
        }

        return $book->refresh();
    }

    /**
     * The HTTP payload for creating/updating a book.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bookPayload(BookCategory $category, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Introduction to Algorithms',
            'code' => 'BK-ALGO-001',
            'isbn' => '978-0-262-03384-8',
            'book_category_id' => $category->id,
            'publisher_id' => null,
            'author_ids' => [],
            'edition' => '3rd ed.',
            'publication_year' => 2009,
            'language' => 'English',
            'description' => 'The classic algorithms textbook.',
            'status' => Book::STATUS_ACTIVE,
        ], $overrides);
    }
}
