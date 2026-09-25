<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 2) — the stock movement ledger.
 *
 * One immutable row per change of on-hand quantity. Movements are append-only:
 * there is no update or delete route and no soft-delete column, mirroring the
 * Communication logs. A correction is a new movement, never an edit — that is
 * what keeps the ledger and `inventory_items.quantity` reconcilable.
 *
 *   - `type` records WHY the stock moved (purchase receipt, manual stock in /
 *     out, correction, opening balance); `direction` records which way, so the
 *     ledger can be filtered and indexed without decoding the type;
 *   - `quantity` is always a positive magnitude — the direction carries the
 *     sign, so no row can silently negate itself;
 *   - `balance_after` snapshots the on-hand quantity at the moment of writing,
 *     which makes the ledger self-explanatory and detects drift;
 *   - `purchase_order_id` links a goods receipt back to the order it fulfils
 *     and is null for movements that came from nowhere near a purchase order.
 *
 * Issue/return to staff, asset assignment and maintenance are later phases and
 * deliberately have no type here.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->foreign(['item_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->foreign(['purchase_order_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_purchase_orders')
                ->restrictOnDelete();
            $table->string('type', 20);
            $table->string('direction', 3);
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->date('movement_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'item_id'], 'inventory_stock_movements_college_item_idx');
            $table->index(['college_id', 'type'], 'inventory_stock_movements_college_type_idx');
            $table->index(['college_id', 'direction'], 'inventory_stock_movements_college_direction_idx');
            $table->index(['college_id', 'movement_date'], 'inventory_stock_movements_college_date_idx');
            $table->index(['college_id', 'purchase_order_id'], 'inventory_stock_movements_college_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
    }
};
