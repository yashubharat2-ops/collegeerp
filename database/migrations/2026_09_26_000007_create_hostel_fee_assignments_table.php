<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management Phase 2 — Hostel Fee Assignment.
 *
 * Assigns one HostelFeeStructure to one existing HostelAllocation.
 * The student, enrollment, academic year, hostel hierarchy are NOT copied —
 * they stay owned by the allocation. academic_year_id is stamped server-side
 * from the allocation for filtering.
 *
 * assigned_amount is a deliberate FINANCIAL FACT: the fee structure's amount
 * snapshotted server-side at assignment time, so later edits to the structure
 * never silently re-price an existing assignment.
 *
 * Money is NOT stored here beyond the snapshot. Collections are the EXISTING
 * Finance fee_payments rows pointed at this assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('hostel_allocation_id')->constrained('hostel_allocations')->restrictOnDelete();
            $table->foreignId('hostel_fee_structure_id')->constrained('hostel_fee_structures')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->decimal('assigned_amount', 12, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'hostel_fee_structure_id'], 'hostel_fee_assignments_structure_idx');
            $table->index(['college_id', 'academic_year_id'], 'hostel_fee_assignments_year_idx');
            $table->index(['college_id', 'hostel_allocation_id'], 'hostel_fee_assignments_allocation_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            // Prevent exact duplicate active assignments for same allocation + structure + period start.
            DB::statement("CREATE UNIQUE INDEX hostel_fee_assignments_active_unique ON hostel_fee_assignments (college_id, hostel_allocation_id, hostel_fee_structure_id, effective_from) WHERE deleted_at IS NULL AND status = 'active'");
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `hostel_fee_assignments` ADD CONSTRAINT `hostel_fee_assignments_amount_positive` CHECK (`assigned_amount` > 0)');
            DB::statement('ALTER TABLE `hostel_fee_assignments` ADD CONSTRAINT `hostel_fee_assignments_dates_check` CHECK (`effective_until` IS NULL OR `effective_until` >= `effective_from`)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "hostel_fee_assignments" ADD CONSTRAINT "hostel_fee_assignments_amount_positive" CHECK ("assigned_amount" > 0)');
            DB::statement('ALTER TABLE "hostel_fee_assignments" ADD CONSTRAINT "hostel_fee_assignments_dates_check" CHECK ("effective_until" IS NULL OR "effective_until" >= "effective_from")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_fee_assignments');
    }
};
