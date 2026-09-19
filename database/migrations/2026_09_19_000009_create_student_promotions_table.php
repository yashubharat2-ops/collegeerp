<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tenant-aware student promotions.
     *
     * A promotion is the auditable DECISION that moves a student from one
     * enrollment to the next. It never mutates or deletes the source
     * enrollment: approving a promotion creates a NEW StudentEnrollment for
     * the target academic year (server-generated enrollment number) and only
     * flips the preserved source enrollment's status to `completed`.
     *
     * The source/target academic context is snapshotted onto this row so the
     * decision stays readable even after the referenced Section, Program or
     * Academic Year is later archived or soft-deleted. All target references
     * point at existing Platform master data (academic_years, programs,
     * academic_terms, sections) — no promotion-local copies are created.
     *
     * No progression rule is encoded anywhere: "which year follows which" is
     * left entirely to the operator's choice of target academic year, so the
     * module works for semester, annual, lateral-entry and re-admission cases.
     */
    public function up(): void
    {
        Schema::create('student_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // Source context (the enrollment being promoted out of).
            $table->foreignId('source_enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->foreignId('source_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('source_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('source_section_id')->nullable()->constrained('sections')->nullOnDelete();

            // Target context (what the student is promoted into).
            $table->foreignId('target_academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('target_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('target_academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('target_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('target_enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();

            $table->date('effective_date')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('remarks')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'student_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'target_academic_year_id']);
            $table->index(['student_id', 'target_academic_year_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotions');
    }
};
