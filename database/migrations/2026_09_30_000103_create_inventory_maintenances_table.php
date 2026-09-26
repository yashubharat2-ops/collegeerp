<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 3) — asset maintenance.
 *
 * One row is one maintenance event (preventive service, repair, inspection,
 * calibration, other) for an individual ASSET — always a reference to an
 * existing `inventory_items` row with `item_type = 'asset'`, so a
 * maintenance record can never outlive its asset and never points at
 * another college's record (composite foreign key `(item_id, college_id)`).
 *
 * Maintenance does not touch stock: there is no quantity and no ledger
 * involvement. Optional `vendor_id` (composite foreign key) reuses the
 * Phase 1 vendor master for work carried out externally.
 *
 * Unlike assignment history, a maintenance record is a live work order: it
 * can be edited (status walks scheduled → in progress → completed, costs
 * are filled in as the work happens), which is why the table carries soft
 * deletes instead of being append-only. There is still no delete route in
 * Phase 3 — records are corrected, not thrown away.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->foreign(['item_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_items')
                ->restrictOnDelete();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->foreign(['vendor_id', 'college_id'])
                ->references(['id', 'college_id'])
                ->on('inventory_vendors')
                ->restrictOnDelete();
            $table->string('title', 255);
            $table->string('maintenance_type', 30);
            $table->string('status', 20)->default('scheduled')->index();
            $table->date('scheduled_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('performed_by', 255)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'item_id'], 'inventory_maintenances_college_item_idx');
            $table->index(['college_id', 'status'], 'inventory_maintenances_college_status_idx');
            $table->index(['college_id', 'vendor_id'], 'inventory_maintenances_college_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_maintenances');
    }
};
