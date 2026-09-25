<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 2) — purchase orders.
 *
 * A purchase order is the college's request to a vendor for a set of items.
 * It is a tenant-scoped header with its own line table
 * (`inventory_purchase_order_items`); nothing is paid here — Finance owns
 * payments and this phase creates no payment rows.
 *
 * `number` is stored upper-cased and unique among the college's active rows,
 * exactly like the Phase 1 masters' codes. The composite foreign key
 * `(vendor_id, college_id)` guarantees the vendor belongs to the same college.
 *
 * `total_amount` is a server-computed sum of the line totals; it is never
 * taken from request data.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('vendor_id');
            $table->foreign(['vendor_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_vendors')
                ->restrictOnDelete();
            $table->string('number', 50);
            $table->date('po_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'number'], 'inventory_purchase_orders_college_number_idx');
            $table->index(['college_id', 'status'], 'inventory_purchase_orders_college_status_idx');
            $table->index(['college_id', 'vendor_id'], 'inventory_purchase_orders_college_vendor_idx');
            $table->index(['college_id', 'po_date'], 'inventory_purchase_orders_college_date_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX inventory_purchase_orders_active_number_unique ON inventory_purchase_orders (college_id, number) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_orders');
    }
};
