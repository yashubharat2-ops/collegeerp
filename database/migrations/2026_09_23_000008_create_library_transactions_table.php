<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 2) — issue / return transactions.
 *
 * One row is one lending of a physical copy to a library member. Returning or
 * marking the item lost updates this row; it is never deleted. The original
 * issue date and the actor who issued it stay on the row. Due-date changes are
 * recorded as library_renewals rather than by overwriting issued_on.
 *
 * At most one open (`status = issued`) transaction may exist per copy. That
 * partial unique index is the database-level concurrency guard on
 * SQLite/PostgreSQL; MySQL/MariaDB rely on the same rule enforced inside a
 * transaction that locks the copy row (and the college row).
 *
 * No soft deletes: circulation history is append-only from the application's
 * point of view. Cascades exist only so a college hard-delete can remove a
 * tenant; the UI has no delete route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_copy_id')->constrained('book_copies')->cascadeOnDelete();
            $table->foreignId('library_member_id')->constrained('library_members')->cascadeOnDelete();
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('returned_on')->nullable();
            $table->string('status', 20)->default('issued')->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'status'], 'library_tx_college_status_idx');
            $table->index(['college_id', 'issued_on'], 'library_tx_college_issued_idx');
            $table->index(['college_id', 'due_on'], 'library_tx_college_due_idx');
            $table->index(['college_id', 'book_copy_id'], 'library_tx_college_copy_idx');
            $table->index(['college_id', 'library_member_id'], 'library_tx_college_member_idx');
            $table->index(['college_id', 'status', 'due_on'], 'library_tx_college_open_due_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX library_transactions_open_copy_uniq ON library_transactions (book_copy_id) WHERE status = 'issued'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('library_transactions');
    }
};
