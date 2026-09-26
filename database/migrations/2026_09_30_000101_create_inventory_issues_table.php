<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 3) — item issue / allocation.
 *
 * One row is one issue of a CONSUMABLE item's stock to a student or a staff
 * member (faculty). The stock itself never moves in this table: the issue
 * writes a `stock_out` row to the existing Phase 2 ledger
 * (`inventory_stock_movements`) through InventoryStockService in the same
 * transaction, and this row keeps the allocation behind it — who received
 * what, why, and the auto-generated issue number that appears as the
 * movement's reference.
 *
 * Rows are append-only (no soft deletes, no update / delete routes): an
 * issue cannot be undone, corrected stock comes back in through a new
 * movement, mirroring the ledger's immutability.
 *
 * Composite foreign key `(item_id, college_id)` guarantees the item belongs
 * to the same college; `number` is unique per college and generated
 * server-side.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->foreign(['item_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->string('number', 50);
            $table->decimal('quantity', 12, 2);
            $table->string('issued_to_type', 50);
            $table->unsignedBigInteger('issued_to_id');
            $table->string('purpose', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('movement_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['college_id', 'number'], 'inventory_issues_college_number_unique');
            $table->index(['college_id', 'item_id'], 'inventory_issues_college_item_idx');
            $table->index(['college_id', 'movement_date'], 'inventory_issues_college_date_idx');
            $table->index(['college_id', 'issued_to_type', 'issued_to_id'], 'inventory_issues_college_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_issues');
    }
};
