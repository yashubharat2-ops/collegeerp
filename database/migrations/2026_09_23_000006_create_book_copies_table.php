<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 2) — physical book copies.
 *
 * A copy is one physical item of an existing Book master. It does not duplicate
 * bibliographic data (title, ISBN, authors): those stay on `books`. Circulation
 * status lives here; issue / return history lives on `library_transactions`.
 *
 * Identifiers unique among the college's active (not soft-deleted) copies:
 *   - accession_number (required)
 *   - barcode (optional; NULL never collides)
 *   - copy_number within a single book
 *
 * Additive only: no existing table is modified. `book_id` cascades so a college
 * hard-delete (colleges are soft-deleted in the UI) can remove a tenant;
 * application code refuses to delete a copy that has transaction history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('accession_number', 50);
            $table->string('barcode', 64)->nullable();
            $table->unsignedInteger('copy_number');
            $table->string('location', 255)->nullable();
            $table->string('condition', 20)->default('good');
            $table->string('status', 20)->default('available')->index();
            $table->date('acquired_on')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'book_id'], 'book_copies_college_book_idx');
            $table->index(['college_id', 'accession_number'], 'book_copies_college_accession_idx');
            $table->index(['college_id', 'barcode'], 'book_copies_college_barcode_idx');
            $table->index(['college_id', 'status'], 'book_copies_college_status_idx');
            $table->index(['college_id', 'condition'], 'book_copies_college_condition_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX book_copies_active_accession_uniq ON book_copies (college_id, accession_number) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX book_copies_active_barcode_uniq ON book_copies (college_id, barcode) WHERE deleted_at IS NULL AND barcode IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX book_copies_active_number_uniq ON book_copies (book_id, copy_number) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('book_copies');
    }
};
