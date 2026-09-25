<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 2) — purchase order lines.
 *
 * One row per ordered item. Lines are children of the header: they carry the
 * college themselves so every composite foreign key stays tenant-safe, and an
 * item may appear only once per order (`(purchase_order_id, item_id)`).
 *
 * `received_quantity` accumulates as goods are received, so the remaining
 * quantity of a line is always derivable and a purchase order can be received
 * in several consignments. Lines are never soft-deleted on their own — they
 * follow their header.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->foreign(['purchase_order_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->foreign(['item_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('received_quantity', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'item_id'], 'inventory_po_items_order_item_unique');
            $table->index(['college_id', 'item_id'], 'inventory_po_items_college_item_idx');
            $table->index(['college_id', 'purchase_order_id'], 'inventory_po_items_college_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_order_items');
    }
};
