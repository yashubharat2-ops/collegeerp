<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 1) — item category master.
 *
 * A category is a tenant-scoped classification shared by consumable items and
 * assets (Stationery, Furniture, Lab Equipment, …). There is no separate asset
 * taxonomy. `code` is unique among the college's active (not soft-deleted)
 * categories. `(id, college_id)` is the composite anchor child items use so a
 * category from another college can never be referenced.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Composite tenant anchor for inventory_items.category_id.
            $table->unique(['id', 'college_id']);
            $table->index(['college_id', 'name'], 'inventory_categories_college_name_idx');
            $table->index(['college_id', 'code'], 'inventory_categories_college_code_idx');
            $table->index(['college_id', 'status'], 'inventory_categories_college_status_idx');
        });

        // At most one ACTIVE (not soft-deleted) category per (college, code).
        // Partial unique indexes are supported by SQLite/PostgreSQL; MySQL and
        // MariaDB rely on the same application-level guard the rest of the
        // project uses.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX inventory_categories_active_code_unique ON inventory_categories (college_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_categories');
    }
};
