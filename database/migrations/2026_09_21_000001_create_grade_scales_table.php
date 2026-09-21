<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations Phase 3 — configurable Grade / Pass-Fail rules.
 *
 * A GradeScale is a TENANT-SCOPED, college-owned grading configuration. No
 * grading boundary (A/B/C/D, 33%/40%/50%, …) is hard-coded anywhere in the
 * application: the calculation engine only ever reads whatever the active
 * college has configured here.
 *
 * Additive only: this table owns nothing that Platform / Students /
 * Examinations Phase 1–2 already own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'code'], 'grade_scales_college_code_idx');
            $table->index(['college_id', 'status'], 'grade_scales_college_status_idx');
        });

        // At most one ACTIVE grade scale per (college, code). Soft-deleted rows
        // are history and never block re-creating the code. SQLite and Postgres
        // support partial unique indexes; MySQL/MariaDB cannot express
        // "unique where not soft-deleted", so it relies on the same
        // application-level guard the rest of the project uses.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX grade_scales_active_unique ON grade_scales (college_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_scales');
    }
};
