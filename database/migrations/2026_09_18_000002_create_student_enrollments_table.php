<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tenant-aware student enrollments table.
     *
     * A Student is not tied permanently to one academic year/program: each
     * periodic enrollment is a separate, soft-deletable row that composes the
     * student's historical academic record across multiple academic years.
     *
     * The lifecycle invariant is one non-soft-deleted row per
     * (college_id, student_id, academic_year_id, program_id) tuple across all
     * statuses. A NULL program_id is one shared group; section_id is not part
     * of the tuple. StudentService enforces the rule transactionally on the
     * central creation path and preserves soft-deleted history.
     *
     * No tuple-uniqueness index is declared here. Before any future database
     * constraint is considered, confirm the production engine/version and run
     * the read-only duplicate preflight documented in
     * docs/student_management_foundation_design.md. Do not resolve historical
     * duplicates by deleting or rewriting enrollment records.
     *
     * enrollment_number is server-generated and unique per college.
     */
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('enrollment_number', 50);
            $table->date('enrollment_date');
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'enrollment_number']);
            $table->index(['student_id', 'academic_year_id', 'program_id']);
            $table->index(['college_id', 'student_id']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'academic_year_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
