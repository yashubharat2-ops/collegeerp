<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 1) — book category master.
 *
 * A BookCategory is a TENANT-SCOPED, college-owned classification of the book
 * catalogue (Reference, Textbook, Journal, Fiction, …). Nothing about a
 * particular library's taxonomy is expressed in code: every college defines its
 * own categories.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'name'], 'book_categories_college_name_idx');
            $table->index(['college_id', 'status'], 'book_categories_college_status_idx');
            $table->index(['college_id', 'code'], 'book_categories_college_code_idx');
        });

        // At most one ACTIVE (not soft-deleted) category per (college, code).
        // Partial unique indexes are supported by SQLite/PostgreSQL; MySQL and
        // MariaDB cannot express "unique where not soft-deleted", so they rely on
        // the same application-level guard the rest of the project uses.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX book_categories_active_code_unique ON book_categories (college_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('book_categories');
    }
};
