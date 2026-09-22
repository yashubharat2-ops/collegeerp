<?php

namespace App\Domain\Library\Support;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\Publisher;

/**
 * LibraryFormOptions — the select lists the Library screens share.
 *
 * Every list is read through the tenant-scoped models (CollegeScope), so a
 * controller can only ever offer the active college's masters. Keeping the
 * option lists in one place stops the screens from drifting apart and keeps
 * the controllers thin.
 */
final class LibraryFormOptions
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, BookCategory> */
    public static function categories(): mixed
    {
        return BookCategory::query()->orderBy('name')->get(['id', 'name', 'code', 'status']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Author> */
    public static function authors(): mixed
    {
        return Author::query()->orderBy('name')->get(['id', 'name', 'status']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Publisher> */
    public static function publishers(): mixed
    {
        return Publisher::query()->orderBy('name')->get(['id', 'name', 'status']);
    }

    /**
     * Languages already used by the active college's catalogue, so the form can
     * suggest them (free text stays allowed — nothing is hard-coded).
     *
     * @return array<int, string>
     */
    public static function languages(): array
    {
        return Book::query()
            ->whereNotNull('language')
            ->orderBy('language')
            ->distinct()
            ->pluck('language')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return Book::STATUSES;
    }

    /**
     * The full option set the book form needs.
     *
     * @return array<string, mixed>
     */
    public static function forBookForm(): array
    {
        return [
            'categories' => self::categories(),
            'authors' => self::authors(),
            'publishers' => self::publishers(),
            'languages' => self::languages(),
            'statuses' => self::statuses(),
            'currentYear' => (int) now()->year,
        ];
    }
}
