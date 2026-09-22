<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transport Phase 2 — Finance bridge for Transport Fees.
 *
 * Transport fee collections reuse the EXISTING Finance payment rows: a
 * fee_payment is still the ONLY row that carries a collected amount, receipts
 * stay a printable projection of it, and no second payment/receipt/ledger
 * system is introduced. This migration only makes room for the transport
 * target:
 *
 *  - `transport_fee_assignment_id` points a collection at a transport fee
 *    assignment;
 *  - `student_fee_assignment_id` / `fee_structure_id` become nullable because
 *    a transport collection has neither (the invariant "exactly one target is
 *    set" is enforced by the collection service, which always stamps both
 *    sides server-side).
 *
 * Existing tuition collections are untouched: every existing row keeps its
 * student_fee_assignment_id, and the Finance services keep reading it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->foreignId('student_fee_assignment_id')->nullable()->change();
            $table->foreignId('fee_structure_id')->nullable()->change();
            $table->unsignedBigInteger('transport_fee_assignment_id')->nullable()->after('fee_structure_id');
            $table->foreign('transport_fee_assignment_id', 'fee_payments_transport_fee_assignment_fk')
                ->references('id')->on('student_transport_fee_assignments')->restrictOnDelete();
            $table->index(['college_id', 'transport_fee_assignment_id', 'status'], 'fee_payments_transport_assignment_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `fee_payments` ADD CONSTRAINT `fee_payments_single_target` CHECK ((`student_fee_assignment_id` IS NULL) <> (`transport_fee_assignment_id` IS NULL))');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "fee_payments" ADD CONSTRAINT "fee_payments_single_target" CHECK (("student_fee_assignment_id" IS NULL) <> ("transport_fee_assignment_id" IS NULL))');
        }
        // SQLite cannot add a CHECK constraint to an existing table; the
        // collection service enforces the same invariant server-side there.
    }

    public function down(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropIndex('fee_payments_transport_assignment_idx');
            $table->dropForeign('fee_payments_transport_fee_assignment_fk');
            $table->dropColumn('transport_fee_assignment_id');
            $table->foreignId('student_fee_assignment_id')->nullable(false)->change();
            $table->foreignId('fee_structure_id')->nullable(false)->change();
        });
    }
};
