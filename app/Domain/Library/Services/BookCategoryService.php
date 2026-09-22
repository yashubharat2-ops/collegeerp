<?php

namespace App\Domain\Library\Services;

use App\Models\BookCategory;
use App\Models\College;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BookCategoryService — CRUD for the tenant-scoped book category master.
 *
 * Guarantees: a category always belongs to the ACTIVE college, its code is
 * unique among the college's active (not soft-deleted) categories, and a
 * category that still classifies books cannot be removed (mark it inactive
 * instead), so no book is ever left pointing at a missing classification.
 */
class BookCategoryService
{
    private const DUPLICATE_CODE_MESSAGE = 'A book category with this code already exists for the active college.';

    private const IN_USE_MESSAGE = 'This category still classifies books and cannot be deleted. Mark it inactive instead.';

    private const AUDITED = ['name', 'code', 'status', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, code: string, status: string, description?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): BookCategory
    {
        return DB::transaction(function () use ($college, $data, $actor): BookCategory {
            $category = new BookCategory([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'],
                'description' => ($data['description'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->assertUniqueActiveCode($category);

            try {
                $category->save();
            } catch (QueryException) {
                // Partial unique index (SQLite/PostgreSQL) rejected a racing
                // insert; MySQL/MariaDB rely on the guard above.
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('book_categories.created', $category, [], $category->only(self::AUDITED));

            return $category->refresh();
        });
    }

    /**
     * @param  array{name?: string, code?: string, status?: string, description?: string|null}  $data
     */
    public function update(BookCategory $category, array $data, User $actor): BookCategory
    {
        $this->assertTenant($category);

        return DB::transaction(function () use ($category, $data, $actor): BookCategory {
            $old = $category->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $category->{$field} = $field === 'description'
                    ? ($data[$field] ?: null)
                    : $data[$field];
            }

            $category->updated_by = $actor->getKey();

            $this->assertUniqueActiveCode($category);

            try {
                $category->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('book_categories.updated', $category, $old, $category->only(self::AUDITED));

            return $category->refresh();
        });
    }

    /**
     * Soft delete. Refused while books still reference the category: the
     * classification of an existing title must never silently disappear.
     */
    public function delete(BookCategory $category, User $actor): void
    {
        $this->assertTenant($category);

        DB::transaction(function () use ($category): void {
            if ($category->books()->exists()) {
                throw ValidationException::withMessages(['category' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $category->only(self::AUDITED);
            $category->delete();

            $this->audit->record('book_categories.deleted', $category, $snapshot, []);
        });
    }

    private function assertUniqueActiveCode(BookCategory $category): void
    {
        $duplicate = BookCategory::withoutGlobalScopes()
            ->where('college_id', $category->college_id)
            ->where('code', $category->code)
            ->whereNull('deleted_at')
            ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }
    }

    private function assertTenant(BookCategory $category): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $category->college_id === (int) $collegeId, 403);
    }
}
