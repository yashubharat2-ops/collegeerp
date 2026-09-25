<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory — give every composite tenant foreign key a resolvable parent key.
 *
 * This module follows the project convention of composite foreign keys such as
 * `(item_id, college_id)` referencing `(id, college_id)`, which is what keeps a
 * row from ever pointing at another college's record. SQLite, however, resolves
 * a child foreign key against a parent index when the statement is PREPARED, and
 * it requires a UNIQUE index in the parent whose columns are the referenced
 * ones. It ignores partial indexes, and every unique index these tables had was
 * partial (`… WHERE deleted_at IS NULL`, the soft-delete-aware code/number
 * uniqueness indexes).
 *
 * The result was that any insert into a child table failed with
 *
 *     foreign key mismatch - "inventory_stock_movements" referencing
 *     "inventory_purchase_orders"
 *
 * — for every row, including one whose foreign key column is NULL, because the
 * failure happens before any value is looked at. That is what broke writing an
 * opening stock movement.
 *
 * The fix is the parent key SQLite is looking for: a plain (non-partial) UNIQUE
 * index on `(id, college_id)`. `id` is already unique on its own, so the index
 * can never be violated by existing data; it exists purely to make the composite
 * relationship resolvable, and it is what turns the cross-tenant case into a
 * real `FOREIGN KEY constraint failed` rejection instead of a silent mismatch.
 *
 * Additive only: no column and no existing index is changed.
 */
return new class extends Migration
{
    /**
     * Tables that a composite `(x_id, college_id)` foreign key points at.
     *
     * @var list<string>
     */
    private const REFERENCED_TABLES = [
        'inventory_categories',
        'inventory_vendors',
        'inventory_items',
        'inventory_purchase_orders',
    ];

    private function indexName(string $table): string
    {
        return "{$table}_id_college_unique";
    }

    public function up(): void
    {
        foreach (self::REFERENCED_TABLES as $table) {
            if (! Schema::hasTable($table) || $this->hasParentKey($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unique(['id', 'college_id'], $this->indexName($table));
            });
        }
    }

    public function down(): void
    {
        foreach (self::REFERENCED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! $this->hasParentKey($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($this->indexName($table));
            });
        }
    }

    /**
     * Whether a non-partial unique index already covers (id, college_id).
     */
    private function hasParentKey(string $table): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = array_map(
                fn ($column): string => strtolower((string) $column),
                $index['columns'] ?? [],
            );

            sort($columns);

            if (($index['unique'] ?? false) && $columns === ['college_id', 'id']) {
                return true;
            }
        }

        return false;
    }
};
