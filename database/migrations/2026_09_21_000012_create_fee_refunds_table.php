<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — Refunds.
 *
 * A FeeRefund is always attached to an ACTUAL, non-cancelled FeePayment: it
 * never invents money and never duplicates the payment amount. The refundable
 * amount of a payment is computed live as
 *
 *   payment.amount − Σ (refunds of that payment that are not rejected/cancelled)
 *
 * with the assignment row locked while the refund is recorded, so concurrent
 * requests cannot over-refund.
 *
 * Refunds carry no soft deletes: their lifecycle (pending → approved →
 * processed, or rejected/cancelled) is carried by `status`, and nothing is ever
 * deleted. A cancelled or rejected refund stops reducing the collected amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_payment_id')->constrained('fee_payments')->cascadeOnDelete();
            $table->string('refund_number', 50);
            $table->date('refund_date');
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Refund numbers are server-generated and unique per college.
            $table->unique(['college_id', 'refund_number'], 'fee_refunds_college_number_unique');
            $table->index(['college_id', 'fee_payment_id', 'status'], 'fee_refunds_payment_status_idx');
            $table->index(['college_id', 'refund_date'], 'fee_refunds_college_date_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `fee_refunds` ADD CONSTRAINT `fee_refunds_amount_positive` CHECK (`amount` > 0)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "fee_refunds" ADD CONSTRAINT "fee_refunds_amount_positive" CHECK ("amount" > 0)');
        } elseif ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE [fee_refunds] ADD CONSTRAINT [fee_refunds_amount_positive] CHECK ([amount] > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_refunds');
    }
};
