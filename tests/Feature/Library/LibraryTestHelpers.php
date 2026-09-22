<?php

namespace Tests\Feature\Library;

use App\Models\AcademicYear;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\College;
use App\Models\LibraryMember;
use App\Models\LibraryTransaction;
use App\Models\Publisher;
use App\Models\Student;
use App\Models\StudentEnrollment;
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
        'book_copies.view', 'book_copies.create', 'book_copies.update', 'book_copies.delete',
        'library_members.view', 'library_members.create', 'library_members.update', 'library_members.delete',
        'library_transactions.view', 'library_transactions.create', 'library_transactions.update', 'library_transactions.return',
        'library_renewals.view', 'library_renewals.create',
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
     * A student enrollment the library can attach a membership to.
     * Identity stays on Student; the helper does not invent a member person.
     *
     * @param  array<string, mixed>  $studentOverrides
     * @param  array<string, mixed>  $enrollmentOverrides
     * @return array{0: Student, 1: StudentEnrollment}
     */
    private function makeLibraryEnrollment(College $college, array $studentOverrides = [], array $enrollmentOverrides = []): array
    {
        $suffix = Str::upper(Str::random(5));

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026 '.$suffix,
            'code' => 'AY-'.$suffix,
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $student = Student::create(array_merge([
            'college_id' => $college->id,
            'student_number' => 'STU-'.$suffix,
            'first_name' => 'Asha',
            'last_name' => 'Nair',
            'status' => 'active',
        ], $studentOverrides));

        $enrollment = StudentEnrollment::create(array_merge([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_number' => 'ENR-'.$suffix,
            'enrollment_date' => '2026-06-15',
            'status' => 'active',
        ], $enrollmentOverrides));

        return [$student, $enrollment];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBookCopy(College $college, ?Book $book = null, array $overrides = []): BookCopy
    {
        $book ??= $this->makeBook($college);

        return BookCopy::create(array_merge([
            'college_id' => $college->id,
            'book_id' => $book->id,
            'accession_number' => 'ACC-'.Str::upper(Str::random(6)),
            'copy_number' => 1,
            'condition' => BookCopy::CONDITION_GOOD,
            'status' => BookCopy::STATUS_AVAILABLE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLibraryMember(College $college, ?StudentEnrollment $enrollment = null, array $overrides = []): LibraryMember
    {
        if (! $enrollment) {
            [, $enrollment] = $this->makeLibraryEnrollment($college);
        }

        return LibraryMember::create(array_merge([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollment->id,
            'member_code' => 'LM-'.Str::upper(Str::random(6)),
            'membership_date' => now()->toDateString(),
            'status' => LibraryMember::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * An open issue, with the copy marked issued so the two stay consistent.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeIssuedTransaction(College $college, ?BookCopy $copy = null, ?LibraryMember $member = null, array $overrides = []): LibraryTransaction
    {
        $copy ??= $this->makeBookCopy($college);
        $member ??= $this->makeLibraryMember($college);
        $copy->forceFill(['status' => BookCopy::STATUS_ISSUED])->save();

        return LibraryTransaction::create(array_merge([
            'college_id' => $college->id,
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->subDays(3)->toDateString(),
            'due_on' => now()->addDays(11)->toDateString(),
            'status' => LibraryTransaction::STATUS_ISSUED,
        ], $overrides));
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
