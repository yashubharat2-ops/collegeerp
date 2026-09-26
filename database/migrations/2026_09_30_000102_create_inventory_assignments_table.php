<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 3) — asset assignment history.
 *
 * One row is one lending of an individual ASSET (an `inventory_items` row
 * with `item_type = 'asset'`) to a student or a staff member. Assignment is
 * pure custody, not consumption: the item's on-hand quantity is untouched
 * and no stock movement is written — the assignment history IS the record
 * of who holds the asset.
 *
 * Business invariants:
 *  - an asset has at most ONE active (`status = 'active'`) assignment at a
 *    time. On SQLite/PostgreSQL a partial unique index
 *    `(item_id) WHERE status = 'active'` is the database-level concurrency
 *    guard; other drivers rely on the same rule enforced inside a
 *    transaction that locks the item row (same pattern as library
 *    transactions).
 *  - returning an asset only flips this row to `returned` (plus the return
 *    date, actor and notes). The row is never deleted or overwritten away
 *    from its history, and a later re-assignment is a NEW row — so the full
 *    custody trail of an asset stays in place.
 *
 * Composite foreign key `(item_id, college_id)` guarantees the asset belongs
 * to the same college. No soft deletes: history is append-only.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->foreign(['item_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->string('assigned_to_type', 50);
            $table->unsignedBigInteger('assigned_to_id');
            $table->string('purpose', 255)->nullable();
            $table->date('assigned_on');
            $table->date('returned_on')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('return_notes')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'item_id'], 'inventory_assignments_college_item_idx');
            $table->index(['college_id', 'status'], 'inventory_assignments_college_status_idx');
            $table->index(['college_id', 'assigned_to_type', 'assigned_to_id'], 'inventory_assignments_college_assignee_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX inventory_assignments_active_item_uniq ON inventory_assignments (item_id) WHERE status = 'active'");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS inventory_assignments_active_item_uniq');
        }

        Schema::dropIfExists('inventory_assignments');
    }
};
