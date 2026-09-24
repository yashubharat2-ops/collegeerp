<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management Phase 2 — Finance bridge for Hostel Fees.
 *
 * Hostel fee collections reuse the EXISTING Finance payment rows: a
 * fee_payment is still the ONLY row that carries a collected amount, receipts
 * stay a printable projection of it, and no second payment/receipt/ledger
 * system is introduced. This migration only makes room for the hostel target:
 *
 * - hostel_fee_assignment_id points a collection at a hostel fee assignment
 * - the existing single-target CHECK (student vs transport) is upgraded to
 *   exactly-one-of-three (student, transport, hostel)
 *
 * Existing tuition and transport collections are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('hostel_fee_assignment_id')->nullable()->after('transport_fee_assignment_id');
            $table->foreign('hostel_fee_assignment_id', 'fee_payments_hostel_fee_assignment_fk')
                ->references('id')->on('hostel_fee_assignments')->restrictOnDelete();
            $table->index(['college_id', 'hostel_fee_assignment_id', 'status'], 'fee_payments_hostel_assignment_idx');
        });

        $driver = DB::connection()->getDriverName();

        // Drop old single-target CHECK if present (MySQL / PG).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL: attempt to drop old CHECK, ignore if not exists.
            try {
                DB::statement('ALTER TABLE `fee_payments` DROP CHECK `fee_payments_single_target`');
            } catch (\Throwable $e) {
                // Old constraint may not exist on some MySQL versions or already dropped.
            }

            // New CHECK: exactly one of the three targets is NOT NULL.
            // In MySQL, (col IS NULL) returns 1 when null, 0 otherwise.
            DB::statement('ALTER TABLE `fee_payments` ADD CONSTRAINT `fee_payments_single_target` CHECK ((`student_fee_assignment_id` IS NULL) + (`transport_fee_assignment_id` IS NULL) + (`hostel_fee_assignment_id` IS NULL) = 2)');
        } elseif ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE "fee_payments" DROP CONSTRAINT IF EXISTS "fee_payments_single_target"');
            } catch (\Throwable $e) {
            }

            DB::statement('ALTER TABLE "fee_payments" ADD CONSTRAINT "fee_payments_single_target" CHECK (((CASE WHEN "student_fee_assignment_id" IS NULL THEN 1 ELSE 0 END) + (CASE WHEN "transport_fee_assignment_id" IS NULL THEN 1 ELSE 0 END) + (CASE WHEN "hostel_fee_assignment_id" IS NULL THEN 1 ELSE 0 END)) = 2)');
        }
        // SQLite: no CHECK originally, service enforces invariant.
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                DB::statement('ALTER TABLE `fee_payments` DROP CHECK `fee_payments_single_target`');
            } catch (\Throwable $e) {
            }
        } elseif ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE "fee_payments" DROP CONSTRAINT IF EXISTS "fee_payments_single_target"');
            } catch (\Throwable $e) {
            }
        }

        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropIndex('fee_payments_hostel_assignment_idx');
            $table->dropForeign('fee_payments_hostel_fee_assignment_fk');
            $table->dropColumn('hostel_fee_assignment_id');
        });

        // Restore old two-target CHECK for MySQL / PG.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                DB::statement('ALTER TABLE `fee_payments` ADD CONSTRAINT `fee_payments_single_target` CHECK ((`student_fee_assignment_id` IS NULL) <> (`transport_fee_assignment_id` IS NULL))');
            } catch (\Throwable $e) {
            }
        } elseif ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE "fee_payments" ADD CONSTRAINT "fee_payments_single_target" CHECK (("student_fee_assignment_id" IS NULL) <> ("transport_fee_assignment_id" IS NULL))');
            } catch (\Throwable $e) {
            }
        }
    }
};
