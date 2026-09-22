<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 2) — renewal history.
 *
 * Each renewal is a new row. The issue transaction keeps its original
 * issued_on / issued_by; only its current due_on moves forward, and the
 * previous due date is preserved here as old_due_date. Renewals are never
 * updated or deleted by the application.
 *
 * Additive only. No soft deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_transaction_id')->constrained('library_transactions')->cascadeOnDelete();
            $table->date('old_due_date');
            $table->date('new_due_date');
            $table->date('renewed_on');
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'issue_transaction_id'], 'library_renewals_college_tx_idx');
            $table->index(['college_id', 'renewed_on'], 'library_renewals_college_renewed_idx');
            $table->index(['issue_transaction_id', 'id'], 'library_renewals_tx_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_renewals');
    }
};
