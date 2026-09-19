<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tenant-aware student academic records.
     *
     * An academic record describes a student's academic standing for one
     * academic year / term within one program (and optionally one section).
     * It is a progression + status ledger, NOT a copy of academic master data:
     * academic_years, academic_terms, programs and sections are referenced by
     * foreign key and remain owned by the Platform module.
     *
     * It is deliberately result-agnostic: no marks, grades or exam tables are
     * created here. The `remarks` column and the nullable `academic_term_id`
     * give the future Examination/Result module a stable anchor to attach to
     * without a schema change.
     *
     * One live record per student/year/term. As documented in
     * docs/architecture.md for the equivalent enrollment invariant, this is
     * enforced transactionally in StudentAcademicRecordService (student-row
     * lock) rather than with a hard composite unique, because a hard unique
     * would keep occupying its key after a soft delete and block a legitimate
     * re-entry of the same year/term. A partial unique index
     * (student_id, academic_year_id, academic_term_id) WHERE deleted_at IS NULL
     * should be added once the production database engine is fixed.
     */
    public function up(): void
    {
        Schema::create('student_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();

            // Academic standing for the period.
            $table->string('academic_status', 20)->default('enrolled')->index();
            // Progression outcome for the period.
            $table->string('promotion_status', 20)->default('not_applicable')->index();
            // Completion of the program/stage.
            $table->string('completion_status', 20)->default('pending')->index();

            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'student_id']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'section_id']);
            $table->index(['student_id', 'academic_year_id', 'academic_term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_records');
    }
};
