<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — Student Fee Assignment.
 *
 * Assigns one existing FeeStructure to one existing StudentEnrollment. The
 * student, academic year, program and term are NOT copied here: they are reached
 * through the enrollment, which stays the single source of truth for the
 * student's academic context.
 *
 * `assigned_amount` is a deliberate FINANCIAL FACT, not duplicated master data:
 * it is the server-computed total of the fee structure's active components at
 * assignment time. Snapshotting it here means later edits to the fee structure
 * never silently re-price a student who was already assigned a plan (the
 * historical assignment is preserved), and every downstream figure — payable,
 * concession cap, outstanding — derives from this one number plus the
 * transaction rows (payments, concessions, refunds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->constrained('fee_structures')->cascadeOnDelete();
            $table->decimal('assigned_amount', 12, 2);
            $table->date('assigned_at');
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'student_enrollment_id', 'status'], 'student_fee_assignments_enrollment_status_idx');
            $table->index(['college_id', 'fee_structure_id'], 'student_fee_assignments_structure_idx');
        });

        // At most one PAYABLE (active or completed) assignment of the same fee
        // structure to the same enrollment: a cancelled or soft-deleted row is
        // history and never blocks re-assigning the same plan.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX student_fee_assignments_active_unique ON student_fee_assignments (college_id, student_enrollment_id, fee_structure_id) WHERE deleted_at IS NULL AND status IN ('active', 'completed')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_assignments');
    }
};
