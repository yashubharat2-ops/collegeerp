<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — Fee Discounts / Concessions.
 *
 * A FeeConcession is a transaction against one StudentFeeAssignment: a fixed
 * amount (type = fixed, `value` = money) or a percentage (type = percentage,
 * `value` = 0–100). `amount` is the SERVER-COMPUTED money value of the
 * concession and is never accepted from the browser.
 *
 * Approval is server-controlled: approved_by / approved_at are written only by
 * the approve action. A concession stops counting towards the student's balance
 * only when it is 'rejected' or 'cancelled'; the default 'pending' state is
 * treated as applicable so a college's concession policy is never silently
 * ignored.
 *
 * Nothing is ever hard-deleted: soft deletes plus the audit log preserve the
 * historical record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_concessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_fee_assignment_id')->constrained('student_fee_assignments')->cascadeOnDelete();
            $table->string('type', 20)->default('fixed');
            $table->decimal('value', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'student_fee_assignment_id', 'status'], 'fee_concessions_assignment_status_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `fee_concessions` ADD CONSTRAINT `fee_concessions_amount_non_negative` CHECK (`amount` >= 0)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "fee_concessions" ADD CONSTRAINT "fee_concessions_amount_non_negative" CHECK ("amount" >= 0)');
        } elseif ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE [fee_concessions] ADD CONSTRAINT [fee_concessions_amount_non_negative] CHECK ([amount] >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_concessions');
    }
};
