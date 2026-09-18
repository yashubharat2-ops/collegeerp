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
     * Duplicate-active-enrollment prevention cannot rely on a plain composite
     * unique here: a hard (student_id, academic_year_id, program_id) unique
     * would also occupy its key after a soft delete, blocking a legitimate
     * re-enrollment in the same year/program once the previous one is
     * cancelled. This mirrors the AcademicYear overlap invariant: the
     * "no duplicate ACTIVE enrollment" rule is enforced transactionally in
     * CreateEnrollment (student-row lock) and, once the production database
     * engine is fixed, should become a partial unique index on
     * (student_id, academic_year_id, program_id) WHERE deleted_at IS NULL
     * per docs/architecture.md.
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
