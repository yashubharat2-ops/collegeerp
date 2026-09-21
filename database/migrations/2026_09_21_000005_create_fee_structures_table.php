<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — Fee Structure foundation.
 *
 * A FeeStructure is a TENANT-SCOPED, college-owned fee plan for one academic
 * year, scoped to one program and optionally narrowed to one academic term
 * (semester / trimester). It REFERENCES the existing Platform masters
 * (academic_years, programs, academic_terms) and duplicates none of them.
 *
 * Additive only: nothing that Platform / Students / Academics / Examinations
 * already owns is modified by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();

            // Masters are owned by the Platform module; fee structures only
            // reference them. Same FK behaviour as exam_schedules: a removed
            // academic year / program takes its dependent configuration with it.
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();

            // Nullable by design: a fee structure may cover the whole academic
            // year instead of a single term. When set, the term must belong to
            // the same college AND the same academic year — a contextual rule
            // enforced by the Form Requests and re-checked by the service,
            // because a portable composite FK would require a new unique key on
            // academic_terms (see docs/architecture.md).
            //
            // nullOnDelete rather than cascadeOnDelete: removing a term must not
            // silently delete money-bearing configuration, it only widens the
            // structure back to year level.
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();

            $table->string('name');
            $table->string('code', 50);
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'academic_year_id', 'program_id'], 'fee_structures_scope_idx');
            $table->index(['college_id', 'academic_term_id'], 'fee_structures_term_idx');
            $table->index(['college_id', 'status'], 'fee_structures_college_status_idx');
        });

        // At most one ACTIVE (i.e. not soft-deleted) fee structure per
        // (college, academic year, program, code). Soft-deleted rows are history
        // and never block re-creating the code. SQLite and PostgreSQL support
        // partial unique indexes; MySQL / MariaDB cannot express "unique where
        // not soft-deleted" (and older MySQL ignores CHECK-based workarounds), so
        // they rely on the same application-level guard the rest of the project
        // uses — see docs/architecture.md.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX fee_structures_active_unique ON fee_structures (college_id, academic_year_id, program_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};
