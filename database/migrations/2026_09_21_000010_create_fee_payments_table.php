<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — Fee Collection (payments).
 *
 * A FeePayment is the ONLY place a collected amount is recorded. Receipts are
 * derived from it and never store a second amount, so there is exactly one
 * financial fact per collection.
 *
 * The academic context is reached through the assignment/enrollment reference
 * (`fee_structure_id` is kept as an indexed pointer for reporting, always
 * stamped server-side from the assignment — never from request data).
 *
 * Cancellation metadata (cancelled_by / cancelled_at / cancellation_reason) is
 * stored so a reversal is traceable without deleting anything: cancelled rows
 * stay in the table and are excluded from every balance calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_fee_assignment_id')->constrained('student_fee_assignments')->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->constrained('fee_structures')->cascadeOnDelete();
            $table->string('payment_number', 50);
            $table->date('payment_date');
            $table->string('payment_mode', 30)->default('cash')->index();
            $table->decimal('amount', 12, 2);
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('completed')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Payment numbers are server-generated and unique per college.
            $table->unique(['college_id', 'payment_number'], 'fee_payments_college_number_unique');
            $table->index(['college_id', 'student_enrollment_id', 'status'], 'fee_payments_enrollment_status_idx');
            $table->index(['college_id', 'payment_date'], 'fee_payments_college_date_idx');
            $table->index(['college_id', 'payment_mode'], 'fee_payments_college_mode_idx');
        });

        // A collection is always a positive amount. SQLite cannot add a CHECK
        // constraint to an existing table and in-memory test databases are built
        // through Blueprint, so — like academic_years — the constraint is added
        // on the engines that support it and the application layers cover SQLite.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `fee_payments` ADD CONSTRAINT `fee_payments_amount_positive` CHECK (`amount` > 0)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "fee_payments" ADD CONSTRAINT "fee_payments_amount_positive" CHECK ("amount" > 0)');
        } elseif ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE [fee_payments] ADD CONSTRAINT [fee_payments_amount_positive] CHECK ([amount] > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
    }
};
