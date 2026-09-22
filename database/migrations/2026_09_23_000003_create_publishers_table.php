<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 1) — publisher master.
 *
 * Publishers are reusable, TENANT-SCOPED records referenced by books. The
 * optional contact fields exist so procurement can reach the publisher; no
 * purchase or accounting logic lives here.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Case-folded copy of `name` (see authors) for the duplicate guard.
            $table->string('name_normalized');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'name'], 'publishers_college_name_idx');
            $table->index(['college_id', 'name_normalized'], 'publishers_college_name_normalized_idx');
            $table->index(['college_id', 'status'], 'publishers_college_status_idx');
        });

        // At most one ACTIVE (not soft-deleted) publisher per (college, normalized name).
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX publishers_active_name_unique ON publishers (college_id, name_normalized) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('publishers');
    }
};
