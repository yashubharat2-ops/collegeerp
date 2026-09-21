<?php

namespace App\Domain\Finance\Services;

use App\Models\College;
use App\Models\FeeCategory;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FeeCategoryService — CRUD for the tenant-scoped fee category master.
 *
 * The category is pure classification: it holds no amounts and owns no fee
 * policy. Its guarantees are that a category always belongs to the ACTIVE
 * college and that a code is unique among the college's active categories.
 */
class FeeCategoryService
{
    private const DUPLICATE_CODE_MESSAGE = 'A fee category with this code already exists for the active college.';

    private const AUDITED = ['name', 'code', 'status', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, code: string, status: string, description?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): FeeCategory
    {
        return DB::transaction(function () use ($college, $data, $actor): FeeCategory {
            $category = new FeeCategory([
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
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('fee_categories.created', $category, [], $category->only(self::AUDITED));

            return $category->refresh();
        });
    }

    /**
     * @param  array{name?: string, code?: string, status?: string, description?: string|null}  $data
     */
    public function update(FeeCategory $category, array $data, User $actor): FeeCategory
    {
        $this->assertTenant($category);

        return DB::transaction(function () use ($category, $data, $actor): FeeCategory {
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

            $this->audit->record('fee_categories.updated', $category, $old, $category->only(self::AUDITED));

            return $category->refresh();
        });
    }

    /**
     * Soft delete. Fee components that referenced the category keep working:
     * their fee_category_id is nulled by the foreign key, and the category row
     * itself stays in the database for the audit trail.
     */
    public function delete(FeeCategory $category, User $actor): void
    {
        $this->assertTenant($category);

        DB::transaction(function () use ($category, $actor): void {
            $snapshot = $category->only(self::AUDITED);
            $category->delete();

            $this->audit->record('fee_categories.deleted', $category, $snapshot, []);
        });
    }

    private function assertUniqueActiveCode(FeeCategory $category): void
    {
        $duplicate = FeeCategory::withoutGlobalScopes()
            ->where('college_id', $category->college_id)
            ->where('code', $category->code)
            ->whereNull('deleted_at')
            ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }
    }

    private function assertTenant(FeeCategory $category): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $category->college_id === (int) $collegeId, 403);
    }
}
