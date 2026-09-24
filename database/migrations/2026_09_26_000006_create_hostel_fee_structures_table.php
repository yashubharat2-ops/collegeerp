<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management Phase 2 — Hostel Fee Structure (pricing master).
 *
 * A college-owned fee definition for hostel charges, per academic year.
 * Amounts are configured per college — nothing is hard-coded. The amount is
 * snapshotted onto each student assignment at assignment time, so editing
 * this row never re-prices history.
 *
 * This is NOT a Finance table: it holds no payments, receipts or balances.
 * Collections happen through the existing Finance fee_payments rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->string('name', 255);
            $table->string('code', 50);
            $table->decimal('amount', 12, 2);
            $table->string('frequency', 50)->nullable()->comment('one-time, monthly, yearly, semester, etc');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'academic_year_id', 'status'], 'hostel_fee_structures_year_status_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `hostel_fee_structures` ADD CONSTRAINT `hostel_fee_structures_amount_positive` CHECK (`amount` > 0)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "hostel_fee_structures" ADD CONSTRAINT "hostel_fee_structures_amount_positive" CHECK ("amount" > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_fee_structures');
    }
};
