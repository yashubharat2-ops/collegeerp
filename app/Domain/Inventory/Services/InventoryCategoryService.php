<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\InventoryCategory;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryCategoryService — CRUD for the tenant-scoped item category master.
 *
 * Guarantees: a category always belongs to the ACTIVE college, its code is
 * unique among the college's active (not soft-deleted) categories, and a
 * category that still classifies items cannot be removed (mark it inactive
 * instead), so no item is ever left pointing at a missing classification.
 */
class InventoryCategoryService
{
    private const DUPLICATE_CODE_MESSAGE = 'An item category with this code already exists for the active college.';

    private const IN_USE_MESSAGE = 'This category still classifies items and cannot be deleted. Mark it inactive instead.';

    private const AUDITED = ['name', 'code', 'status', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, code: string, status: string, description?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): InventoryCategory
    {
        return DB::transaction(function () use ($college, $data, $actor): InventoryCategory {
            $category = new InventoryCategory([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'name' => trim((string) $data['name']),
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

            $this->audit->record('inventory_categories.created', $category, [], $category->only(self::AUDITED));

            return $category->refresh();
        });
    }

    /**
     * @param  array{name?: string, code?: string, status?: string, description?: string|null}  $data
     */
    public function update(InventoryCategory $category, array $data, User $actor): InventoryCategory
    {
        $this->assertTenant($category);

        return DB::transaction(function () use ($category, $data, $actor): InventoryCategory {
            $old = $category->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $category->{$field} = match ($field) {
                    'name' => trim((string) $data[$field]),
                    'description' => $data[$field] ?: null,
                    default => $data[$field],
                };
            }

            $category->updated_by = $actor->getKey();

            $this->assertUniqueActiveCode($category);

            try {
                $category->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('inventory_categories.updated', $category, $old, $category->only(self::AUDITED));

            return $category->refresh();
        });
    }

    /**
     * Soft delete. Refused while items still reference the category.
     */
    public function delete(InventoryCategory $category, User $actor): void
    {
        $this->assertTenant($category);

        DB::transaction(function () use ($category): void {
            if ($category->items()->exists()) {
                throw ValidationException::withMessages(['category' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $category->only(self::AUDITED);
            $category->delete();

            $this->audit->record('inventory_categories.deleted', $category, $snapshot, []);
        });
    }

    private function assertUniqueActiveCode(InventoryCategory $category): void
    {
        $duplicate = InventoryCategory::withoutGlobalScopes()
            ->where('college_id', $category->college_id)
            ->where('code', $category->code)
            ->whereNull('deleted_at')
            ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }
    }

    private function assertTenant(InventoryCategory $category): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $category->college_id === (int) $collegeId, 403);
    }
}
