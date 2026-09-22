<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 1) — author master.
 *
 * Authors are reusable, TENANT-SCOPED records referenced by books (a book may
 * have several authors, an author may have written several books). Keeping
 * them as their own master avoids free-text duplicates such as "R. Sharma" /
 * "Sharma, R." across the catalogue.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Case-folded copy of `name`, maintained by the model, so the
            // duplicate guard and its unique index behave identically on
            // SQLite (case-sensitive by default) and MySQL (case-insensitive).
            $table->string('name_normalized');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'name'], 'authors_college_name_idx');
            $table->index(['college_id', 'name_normalized'], 'authors_college_name_normalized_idx');
            $table->index(['college_id', 'status'], 'authors_college_status_idx');
        });

        // At most one ACTIVE (not soft-deleted) author per (college, normalized name).
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX authors_active_name_unique ON authors (college_id, name_normalized) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authors');
    }
};
