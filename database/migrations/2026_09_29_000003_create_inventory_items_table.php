<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 1) — items / assets master.
 *
 * One master covers both consumable items and fixed assets (`item_type`).
 * There is no separate assets table. `code` is unique among the college's
 * active rows. `serial_number`, when recorded, is unique among the college's
 * active rows; several items may have no serial.
 *
 * The composite foreign key `(category_id, college_id)` guarantees the
 * category belongs to the same college. Stock movements, issue/return and
 * asset assignment are later phases and are not represented here.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->foreign(['category_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_categories')
                ->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('item_type', 20);
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('unit', 30);
            $table->decimal('quantity', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'name'], 'inventory_items_college_name_idx');
            $table->index(['college_id', 'code'], 'inventory_items_college_code_idx');
            $table->index(['college_id', 'status'], 'inventory_items_college_status_idx');
            $table->index(['college_id', 'item_type'], 'inventory_items_college_type_idx');
            $table->index(['college_id', 'category_id'], 'inventory_items_college_category_idx');
            $table->index(['college_id', 'serial_number'], 'inventory_items_college_serial_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX inventory_items_active_code_unique ON inventory_items (college_id, code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX inventory_items_active_serial_unique ON inventory_items (college_id, serial_number) WHERE deleted_at IS NULL AND serial_number IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
