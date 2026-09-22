<?php

namespace Tests\Feature\Library;

use App\Models\Book;
use App\Models\BookCategory;
use Tests\TestCase;

/**
 * Library Management — Library Dashboard.
 *
 * The dashboard is a read-only aggregation of the Phase 1 masters: its numbers
 * must match the records of the active college only, it must be gated by
 * `library_dashboard.view`, and it must render sensibly when the catalogue is
 * empty. No dashboard table exists, so nothing here asserts on stored rows.
 */
class LibraryDashboardTest extends TestCase
{
    use LibraryTestHelpers;

    public function test_the_dashboard_shows_tenant_scoped_totals_and_breakdowns(): void
    {
        $college = $this->makeCollege('LDASH1');
        $other = $this->makeCollege('LDASH1X');
        $user = $this->makeUserWithPermissions($college, ['library_dashboard.view', 'books.view']);

        $fiction = $this->makeBookCategory($college, ['name' => 'Fiction Shelf', 'code' => 'FIC']);
        $science = $this->makeBookCategory($college, ['name' => 'Science Shelf', 'code' => 'SCI']);
        $this->makeBookCategory($college, ['name' => 'Empty Shelf', 'code' => 'EMP', 'status' => BookCategory::STATUS_INACTIVE]);
        $publisher = $this->makePublisher($college, ['name' => 'Dashboard Press']);
        $author = $this->makeAuthor($college, ['name' => 'Prolific Writer']);
        $this->makeAuthor($college, ['name' => 'Silent Writer', 'status' => 'inactive']);

        $this->makeBook($college, ['title' => 'Alpha Title', 'book_category_id' => $fiction->id, 'publisher_id' => $publisher->id, 'language' => 'English', 'publication_year' => 1995], [$author]);
        $this->makeBook($college, ['title' => 'Beta Title', 'book_category_id' => $fiction->id, 'language' => 'Hindi', 'publication_year' => 2011, 'isbn' => '9780262033848'], [$author]);
        $this->makeBook($college, ['title' => 'Gamma Title', 'book_category_id' => $science->id, 'status' => Book::STATUS_INACTIVE]);

        // Another college's data must never leak into the figures.
        $this->makeBook($other, ['title' => 'Foreign Title']);
        $this->makeAuthor($other, ['name' => 'Foreign Writer']);
        $this->makePublisher($other, ['name' => 'Foreign Press']);

        $response = $this->asCollege($college, $user)->get(route('library.dashboard'));

        $response->assertOk()
            ->assertViewIs('library_dashboard.index')
            ->assertViewHas('totalBooks', 3)
            ->assertViewHas('totalCategories', 3)
            ->assertViewHas('totalAuthors', 2)
            ->assertViewHas('totalPublishers', 1)
            ->assertViewHas('totals', fn (array $totals) => $totals['active_books'] === 2
                && $totals['inactive_books'] === 1
                && $totals['books_without_isbn'] === 2
                && $totals['books_added_this_month'] === 3
                && $totals['active_categories'] === 2
                && $totals['active_authors'] === 1
                && $totals['active_publishers'] === 1)
            ->assertViewHas('booksPerCategory', fn ($rows) => $rows->pluck('books_count', 'code')->all() === ['FIC' => 2, 'SCI' => 1, 'EMP' => 0])
            ->assertViewHas('topAuthors', fn ($rows) => $rows->pluck('books_count', 'name')->all() === ['Prolific Writer' => 2, 'Silent Writer' => 0])
            ->assertViewHas('topPublishers', fn ($rows) => $rows->pluck('books_count', 'name')->all() === ['Dashboard Press' => 1])
            ->assertViewHas('booksPerLanguage', fn ($rows) => $rows->pluck('count', 'language')->all() === ['English' => 1, 'Hindi' => 1, 'Not specified' => 1])
            ->assertViewHas('booksPerDecade', fn ($rows) => $rows->pluck('count', 'decade')->all() === ['2010s' => 1, '1990s' => 1, 'Unknown' => 1])
            ->assertViewHas('recentBooks', fn ($rows) => $rows->count() === 3 && $rows->pluck('title')->contains('Alpha Title'))
            ->assertSee('Library Dashboard')
            ->assertSee('Fiction Shelf')
            ->assertSee('Empty Shelf')
            ->assertSee('Prolific Writer')
            ->assertSee('Dashboard Press')
            ->assertSee('Alpha Title')
            ->assertDontSee('Foreign Title')
            ->assertDontSee('Foreign Writer')
            ->assertDontSee('Foreign Press');
    }

    public function test_the_dashboard_renders_an_empty_catalogue(): void
    {
        $college = $this->makeCollege('LDASH2');
        $user = $this->makeUserWithPermissions($college, ['library_dashboard.view']);

        $this->asCollege($college, $user)
            ->get(route('library.dashboard'))
            ->assertOk()
            ->assertViewHas('totalBooks', 0)
            ->assertViewHas('totalCategories', 0)
            ->assertViewHas('totalAuthors', 0)
            ->assertViewHas('totalPublishers', 0)
            ->assertSee('Getting started')
            ->assertSee('No books catalogued yet.');
    }

    public function test_the_dashboard_requires_the_library_dashboard_permission(): void
    {
        $college = $this->makeCollege('LDASH3');
        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        // Holding every other library permission is not enough.
        $librarian = $this->makeUserWithPermissions($college, ['books.view', 'book_categories.view', 'authors.view', 'publishers.view']);

        // Guests are sent to login before any policy runs.
        $this->get(route('library.dashboard'))->assertRedirect(route('login'));

        $this->asCollege($college, $nobody)->get(route('library.dashboard'))->assertForbidden();
        $this->asCollege($college, $librarian)->get(route('library.dashboard'))->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_dashboard_and_quick_links_are_permission_gated(): void
    {
        $college = $this->makeCollege('LDASH4');
        $super = $this->makeSuperAdmin($college);
        $dashboardOnly = $this->makeUserWithPermissions($college, ['library_dashboard.view']);
        $this->makeBook($college, ['title' => 'Linked Title']);

        $this->asCollege($college, $super)
            ->get(route('library.dashboard'))
            ->assertOk()
            ->assertSee(route('books.index'), false)
            ->assertSee(route('book-categories.index'), false)
            ->assertSee(route('authors.index'), false)
            ->assertSee(route('publishers.index'), false);

        // Without books.view the titles are shown as plain text, not as links.
        $this->asCollege($college, $dashboardOnly)
            ->get(route('library.dashboard'))
            ->assertOk()
            ->assertSee('Linked Title')
            ->assertDontSee(route('books.index'), false)
            ->assertDontSee(route('authors.index'), false);
    }
}
