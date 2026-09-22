<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 1) — book master (bibliographic record).
 *
 * A Book is the TENANT-SCOPED description of a title: what the work is, who
 * wrote and published it, and how the library classifies it. It is NOT a
 * physical item: individual copies (accession numbers, shelf locations,
 * condition, availability) are a separate Phase 2 concern and will reference
 * this table. Nothing here can therefore be issued, returned or fined.
 *
 * Identifiers:
 *   - `code` is the library's own catalogue code, required and unique among the
 *     college's active (not soft-deleted) books;
 *   - `isbn` is optional ("where applicable": theses, bound journals and local
 *     publications have none) and, when present, stored normalized (digits and
 *     a trailing X only) and unique among the college's active books.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_category_id')->constrained('book_categories')->restrictOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->string('title');
            $table->string('code', 50);
            $table->string('isbn', 20)->nullable();
            $table->string('edition', 50)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('language', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'title'], 'books_college_title_idx');
            $table->index(['college_id', 'code'], 'books_college_code_idx');
            $table->index(['college_id', 'isbn'], 'books_college_isbn_idx');
            $table->index(['college_id', 'status'], 'books_college_status_idx');
            $table->index(['college_id', 'book_category_id'], 'books_college_category_idx');
            $table->index(['college_id', 'publisher_id'], 'books_college_publisher_idx');
            $table->index(['college_id', 'language'], 'books_college_language_idx');
        });

        // At most one ACTIVE (not soft-deleted) book per (college, code) and,
        // when an ISBN is recorded, per (college, isbn). Partial unique indexes
        // are supported by SQLite/PostgreSQL; MySQL/MariaDB rely on the same
        // application-level guard the rest of the project uses.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX books_active_code_unique ON books (college_id, code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX books_active_isbn_unique ON books (college_id, isbn) WHERE deleted_at IS NULL AND isbn IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
