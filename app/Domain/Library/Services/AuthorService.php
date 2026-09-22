<?php

namespace App\Domain\Library\Services;

use App\Models\Author;
use App\Models\College;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AuthorService — CRUD for the tenant-scoped, reusable author master.
 *
 * Guarantees: an author always belongs to the ACTIVE college, no two active
 * (not soft-deleted) authors of a college share a name (compared
 * case-insensitively with collapsed whitespace), and an author still credited
 * on books cannot be removed.
 */
class AuthorService
{
    private const DUPLICATE_NAME_MESSAGE = 'An author with this name already exists for the active college.';

    private const IN_USE_MESSAGE = 'This author is still credited on books and cannot be deleted. Mark the author inactive instead.';

    private const AUDITED = ['name', 'status', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, status: string, description?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): Author
    {
        return DB::transaction(function () use ($college, $data, $actor): Author {
            $author = new Author([
                'college_id' => $college->getKey(),
                'name' => trim($data['name']),
                'status' => $data['status'],
                'description' => ($data['description'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->assertUniqueActiveName($author);

            try {
                $author->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record('authors.created', $author, [], $author->only(self::AUDITED));

            return $author->refresh();
        });
    }

    /**
     * @param  array{name?: string, status?: string, description?: string|null}  $data
     */
    public function update(Author $author, array $data, User $actor): Author
    {
        $this->assertTenant($author);

        return DB::transaction(function () use ($author, $data, $actor): Author {
            $old = $author->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $author->{$field} = match ($field) {
                    'name' => trim((string) $data[$field]),
                    'description' => $data[$field] ?: null,
                    default => $data[$field],
                };
            }

            $author->updated_by = $actor->getKey();

            $this->assertUniqueActiveName($author);

            try {
                $author->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record('authors.updated', $author, $old, $author->only(self::AUDITED));

            return $author->refresh();
        });
    }

    /**
     * Soft delete. Refused while books still credit the author.
     */
    public function delete(Author $author, User $actor): void
    {
        $this->assertTenant($author);

        DB::transaction(function () use ($author): void {
            if ($author->books()->exists()) {
                throw ValidationException::withMessages(['author' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $author->only(self::AUDITED);
            $author->delete();

            $this->audit->record('authors.deleted', $author, $snapshot, []);
        });
    }

    private function assertUniqueActiveName(Author $author): void
    {
        $duplicate = Author::withoutGlobalScopes()
            ->where('college_id', $author->college_id)
            ->where('name_normalized', Author::normalizeName((string) $author->name))
            ->whereNull('deleted_at')
            ->when($author->exists, fn ($query) => $query->whereKeyNot($author->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    private function assertTenant(Author $author): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $author->college_id === (int) $collegeId, 403);
    }
}
