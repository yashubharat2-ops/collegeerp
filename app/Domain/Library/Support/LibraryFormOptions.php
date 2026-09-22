<?php

namespace App\Domain\Library\Support;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\LibraryMember;
use App\Models\LibraryTransaction;
use App\Models\Publisher;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;

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
     * Books the copy form can attach a physical item to.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Book>
     */
    public static function books(): mixed
    {
        return Book::query()->orderBy('title')->orderBy('id')->get(['id', 'title', 'code', 'status']);
    }

    /**
     * Next free copy number per book (1 when the title has no copies yet).
     *
     * @return array<int, int>
     */
    public static function nextCopyNumbers(): array
    {
        return BookCopy::query()
            ->select('book_id', DB::raw('MAX(copy_number) as max_copy'))
            ->groupBy('book_id')
            ->pluck('max_copy', 'book_id')
            ->map(fn ($max) => (int) $max + 1)
            ->all();
    }

    /**
     * Enrollments a membership can reference. Identity stays on the student.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StudentEnrollment>
     */
    public static function enrollments(): mixed
    {
        return StudentEnrollment::query()
            ->with([
                'student:id,first_name,middle_name,last_name,student_number',
                'academicYear:id,name',
                'program:id,name,code',
            ])
            ->orderBy('enrollment_number')
            ->orderBy('id')
            ->get();
    }

    /**
     * Copies that can be issued right now.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BookCopy>
     */
    public static function availableCopies(): mixed
    {
        return BookCopy::query()
            ->with('book:id,title,code')
            ->where('status', BookCopy::STATUS_AVAILABLE)
            ->orderBy('accession_number')
            ->orderBy('id')
            ->get();
    }

    /**
     * Members who may borrow today: active status and not past expiry.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LibraryMember>
     */
    public static function borrowableMembers(): mixed
    {
        return LibraryMember::query()
            ->with('studentEnrollment.student:id,first_name,middle_name,last_name,student_number')
            ->where('status', LibraryMember::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->orderBy('member_code')
            ->orderBy('id')
            ->get();
    }

    /**
     * Open issues a renewal can extend, soonest due date first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LibraryTransaction>
     */
    public static function openIssues(): mixed
    {
        return LibraryTransaction::query()
            ->with([
                'bookCopy.book:id,title,code',
                'libraryMember.studentEnrollment.student:id,first_name,middle_name,last_name,student_number',
            ])
            ->where('status', LibraryTransaction::STATUS_ISSUED)
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
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
