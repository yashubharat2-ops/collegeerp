<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory / Asset Management (Phase 1) — vendor master.
 *
 * Vendors are reusable, tenant-scoped procurement contacts. `code` is unique
 * among the college's active (not soft-deleted) vendors. Purchase orders are
 * a later phase: nothing here is ordered, received or paid.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'name'], 'inventory_vendors_college_name_idx');
            $table->index(['college_id', 'code'], 'inventory_vendors_college_code_idx');
            $table->index(['college_id', 'status'], 'inventory_vendors_college_status_idx');
            $table->index(['college_id', 'email'], 'inventory_vendors_college_email_idx');
            $table->index(['college_id', 'gst_number'], 'inventory_vendors_college_gst_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX inventory_vendors_active_code_unique ON inventory_vendors (college_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_vendors');
    }
};
