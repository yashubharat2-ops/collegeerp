<?php

namespace App\Domain\Library\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\Publisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LibraryDashboardService — read-only, live aggregations for the Library
 * Dashboard.
 *
 * Deliberately creates NO dashboard or summary tables: every figure is
 * computed from the Phase 1 masters (books, book_categories, authors,
 * publishers, author_book) through the tenant-scoped models, so the numbers
 * can never drift from the records and never leak across colleges.
 */
class LibraryDashboardService
{
    private const TOP = 8;

    /**
     * Headline counters.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        $now = now();

        return [
            'books' => Book::query()->count(),
            'active_books' => Book::query()->where('status', Book::STATUS_ACTIVE)->count(),
            'inactive_books' => Book::query()->where('status', Book::STATUS_INACTIVE)->count(),
            'books_without_isbn' => Book::query()->whereNull('isbn')->count(),
            'books_added_this_month' => Book::query()
                ->where('created_at', '>=', $now->copy()->startOfMonth())
                ->count(),
            'categories' => BookCategory::query()->count(),
            'active_categories' => BookCategory::query()->where('status', BookCategory::STATUS_ACTIVE)->count(),
            'authors' => Author::query()->count(),
            'active_authors' => Author::query()->where('status', Author::STATUS_ACTIVE)->count(),
            'publishers' => Publisher::query()->count(),
            'active_publishers' => Publisher::query()->where('status', Publisher::STATUS_ACTIVE)->count(),
        ];
    }

    /**
     * Book counts per category (every category, including empty ones).
     *
     * @return Collection<int, BookCategory>
     */
    public function booksPerCategory(): Collection
    {
        return BookCategory::query()
            ->withCount('books')
            ->orderByDesc('books_count')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Most-published publishers by book count.
     *
     * @return Collection<int, Publisher>
     */
    public function topPublishers(): Collection
    {
        return Publisher::query()
            ->withCount('books')
            ->orderByDesc('books_count')
            ->orderBy('name')
            ->limit(self::TOP)
            ->get(['id', 'name', 'status']);
    }

    /**
     * Most-credited authors by book count.
     *
     * @return Collection<int, Author>
     */
    public function topAuthors(): Collection
    {
        return Author::query()
            ->withCount('books')
            ->orderByDesc('books_count')
            ->orderBy('name')
            ->limit(self::TOP)
            ->get(['id', 'name', 'status']);
    }

    /**
     * Book counts per language ("Not specified" for books without one).
     *
     * @return Collection<int, array{language: string, count: int}>
     */
    public function booksPerLanguage(): Collection
    {
        // Ordered in PHP so the "Not specified" bucket always comes last —
        // NULL ordering differs between SQLite/MySQL (first) and PostgreSQL (last).
        return Book::query()
            ->select('language', DB::raw('COUNT(*) as total'))
            ->groupBy('language')
            ->get()
            ->map(fn ($row) => [
                'language' => $row->language ?: 'Not specified',
                'count' => (int) $row->total,
            ])
            ->sortBy([
                fn (array $a, array $b) => ($a['language'] === 'Not specified') <=> ($b['language'] === 'Not specified'),
                fn (array $a, array $b) => $b['count'] <=> $a['count'],
                fn (array $a, array $b) => strcasecmp($a['language'], $b['language']),
            ])
            ->values();
    }

    /**
     * Book counts per publication decade, newest first ("Unknown" when the year
     * is not recorded).
     *
     * @return Collection<int, array{decade: string, count: int}>
     */
    public function booksPerDecade(): Collection
    {
        return Book::query()
            ->get(['publication_year'])
            ->groupBy(fn (Book $book) => $book->publication_year
                ? (string) (intdiv((int) $book->publication_year, 10) * 10).'s'
                : 'Unknown')
            ->map(fn (Collection $books, string $decade) => ['decade' => $decade, 'count' => $books->count()])
            ->sortByDesc(fn (array $row) => $row['decade'] === 'Unknown' ? -1 : (int) $row['decade'])
            ->values();
    }

    /**
     * The most recently catalogued titles.
     *
     * @return Collection<int, Book>
     */
    public function recentBooks(): Collection
    {
        return Book::query()
            ->with(['category:id,name,code', 'publisher:id,name', 'authors:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::TOP)
            ->get();
    }
}
