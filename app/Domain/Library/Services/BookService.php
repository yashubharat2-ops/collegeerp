<?php

namespace App\Domain\Library\Services;

use App\Domain\Library\Support\Isbn;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\College;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BookService — CRUD + integrity rules for the book master (Library Management,
 * Phase 1).
 *
 * The service owns no cataloguing policy of its own (categories, authors,
 * publishers and languages are all college data). What it guarantees is that a
 * saved book is structurally sound:
 *
 *   - the book, its category, its publisher and every credited author belong
 *     to the ACTIVE college (contextual FKs — a forged cross-tenant id is
 *     rejected even if the Form Request were bypassed)
 *   - `code` is unique among the college's active (not soft-deleted) books
 *   - `isbn`, when present, is normalized and unique among the college's
 *     active books
 *   - authors are linked in a deterministic, duplicate-free order
 *
 * Tenant safety: college_id is always taken from the authenticated tenant
 * context; a browser-supplied college_id never reaches the database.
 *
 * Scope: the bibliographic master only. Physical copies, members, issue /
 * return, renewals, fines and reports belong to later phases.
 */
class BookService
{
    private const DUPLICATE_CODE_MESSAGE = 'A book with this code already exists for the active college.';

    private const DUPLICATE_ISBN_MESSAGE = 'A book with this ISBN already exists for the active college.';

    private const AUDITED = [
        'title',
        'code',
        'isbn',
        'book_category_id',
        'publisher_id',
        'edition',
        'publication_year',
        'language',
        'status',
        'description',
    ];

    /** Optional scalar fields where an empty form input means "not recorded". */
    private const OPTIONAL = ['isbn', 'edition', 'publication_year', 'language', 'description', 'publisher_id'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload; `author_ids` is an optional ordered list.
     */
    public function create(College $college, array $data, User $actor): Book
    {
        return DB::transaction(function () use ($college, $data, $actor): Book {
            $book = new Book([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'title' => trim((string) $data['title']),
                'code' => $data['code'],
                'book_category_id' => $data['book_category_id'],
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $book->{$field} = $this->optional($field, $data[$field] ?? null);
            }

            $authorIds = $this->normalizeAuthorIds($data['author_ids'] ?? []);

            $this->assertReferencesBelongToCollege($book, $authorIds);
            $this->assertUniqueIdentifiers($book);

            try {
                $book->save();
            } catch (QueryException) {
                // Partial unique index (SQLite/PostgreSQL) rejected a racing
                // insert; MySQL/MariaDB rely on the guard above.
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->syncAuthors($book, $authorIds);

            $this->audit->record('books.created', $book, [], $this->snapshot($book, $authorIds));

            return $book->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload; when `author_ids` is present the credited authors are replaced.
     */
    public function update(Book $book, array $data, User $actor): Book
    {
        $this->assertTenant($book);

        return DB::transaction(function () use ($book, $data, $actor): Book {
            $oldAuthorIds = $book->authors()->pluck('authors.id')->map(fn ($id) => (int) $id)->all();
            $old = $this->snapshot($book, $oldAuthorIds);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $book->{$field} = match (true) {
                    $field === 'title' => trim((string) $data[$field]),
                    in_array($field, self::OPTIONAL, true) => $this->optional($field, $data[$field]),
                    default => $data[$field],
                };
            }

            $book->updated_by = $actor->getKey();

            $authorIds = array_key_exists('author_ids', $data)
                ? $this->normalizeAuthorIds($data['author_ids'] ?? [])
                : $oldAuthorIds;

            $this->assertReferencesBelongToCollege($book, $authorIds);
            $this->assertUniqueIdentifiers($book);

            try {
                $book->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            if (array_key_exists('author_ids', $data)) {
                $this->syncAuthors($book, $authorIds);
            }

            $this->audit->record('books.updated', $book, $old, $this->snapshot($book, $authorIds));

            return $book->refresh();
        });
    }

    /**
     * Soft delete. The bibliographic record stays in the database for the
     * audit trail (and for the Phase 2 copies that may reference it) but is
     * excluded by CollegeScope + SoftDeletes. Author links are kept with it.
     */
    public function delete(Book $book, User $actor): void
    {
        $this->assertTenant($book);

        DB::transaction(function () use ($book): void {
            $authorIds = $book->authors()->pluck('authors.id')->map(fn ($id) => (int) $id)->all();
            $snapshot = $this->snapshot($book, $authorIds);

            $book->delete();

            $this->audit->record('books.deleted', $book, $snapshot, []);
        });
    }

    /**
     * Distinct author ids in submitted order (the order authors are credited).
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalizeAuthorIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }

    /**
     * Contextual FK guard: category, publisher and authors must be active rows
     * of the book's own college.
     *
     * @param  array<int, int>  $authorIds
     */
    private function assertReferencesBelongToCollege(Book $book, array $authorIds): void
    {
        $collegeId = (int) $book->college_id;
        $errors = [];

        $categoryOk = BookCategory::withoutGlobalScopes()
            ->whereKey($book->book_category_id)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $categoryOk) {
            $errors['book_category_id'] = 'The selected book category does not belong to the active college.';
        }

        if ($book->publisher_id !== null) {
            $publisherOk = Publisher::withoutGlobalScopes()
                ->whereKey($book->publisher_id)
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $publisherOk) {
                $errors['publisher_id'] = 'The selected publisher does not belong to the active college.';
            }
        }

        if ($authorIds !== []) {
            $found = Author::withoutGlobalScopes()
                ->whereIn('id', $authorIds)
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->count();

            if ($found !== count($authorIds)) {
                $errors['author_ids'] = 'One or more selected authors do not belong to the active college.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertUniqueIdentifiers(Book $book): void
    {
        $base = fn () => Book::withoutGlobalScopes()
            ->where('college_id', $book->college_id)
            ->whereNull('deleted_at')
            ->when($book->exists, fn ($query) => $query->whereKeyNot($book->getKey()));

        if ($base()->where('code', $book->code)->exists()) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }

        if ($book->isbn !== null && $base()->where('isbn', $book->isbn)->exists()) {
            throw ValidationException::withMessages(['isbn' => self::DUPLICATE_ISBN_MESSAGE]);
        }
    }

    /**
     * Replace the credited authors, keeping submitted order as `sort_order`.
     *
     * @param  array<int, int>  $authorIds
     */
    private function syncAuthors(Book $book, array $authorIds): void
    {
        $pivot = [];

        foreach (array_values($authorIds) as $position => $authorId) {
            $pivot[$authorId] = [
                'college_id' => $book->college_id,
                'sort_order' => $position + 1,
            ];
        }

        $book->authors()->sync($pivot);
        $book->unsetRelation('authors');
    }

    /**
     * @param  array<int, int>  $authorIds
     * @return array<string, mixed>
     */
    private function snapshot(Book $book, array $authorIds): array
    {
        return $book->only(self::AUDITED) + ['author_ids' => array_values($authorIds)];
    }

    private function optional(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'isbn' => Isbn::normalize($value),
            'publication_year', 'publisher_id' => (int) $value,
            default => trim((string) $value) ?: null,
        };
    }

    private function assertTenant(Book $book): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $book->college_id === (int) $collegeId, 403);
    }
}
