<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transport Phase 2 — Student Transport Fee Assignment.
 *
 * Assigns one transport fee structure to one existing STUDENT TRANSPORT
 * ASSIGNMENT. The student, enrollment, academic year, route and stop are NOT
 * copied here — they stay owned by the transport assignment and its
 * enrollment. `academic_year_id` is stamped server-side from the transport
 * assignment for report filtering.
 *
 * `amount` is a deliberate FINANCIAL FACT, not duplicated master data: it is
 * the fee structure's amount snapshotted server-side at assignment time, so
 * later edits to the structure never re-price an existing assignment and the
 * historical assignment is preserved.
 *
 * Money is collected through the EXISTING Finance fee_payments rows (see the
 * fee_payments bridge migration) — this table never stores payments, balances
 * or receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_transport_fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_transport_assignment_id')->constrained('student_transport_assignments')->restrictOnDelete();
            $table->foreignId('transport_fee_structure_id')->constrained('transport_fee_structures')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'transport_fee_structure_id'], 'student_transport_fee_assignments_structure_idx');
            $table->index(['college_id', 'academic_year_id'], 'student_transport_fee_assignments_year_idx');
        });

        // At most one ACTIVE transport fee assignment per transport assignment
        // AND applicability period (same effective_from): a cancelled or
        // soft-deleted row is history and never blocks a new assignment. The
        // service layer additionally rejects OVERLAPPING active periods under a
        // row lock, which a plain unique index cannot express portably.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX student_transport_fee_assignments_active_unique ON student_transport_fee_assignments (college_id, student_transport_assignment_id, effective_from) WHERE deleted_at IS NULL AND status = \'active\'');
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `student_transport_fee_assignments` ADD CONSTRAINT `student_transport_fee_assignments_amount_positive` CHECK (`amount` > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transport_fee_assignments');
    }
};
