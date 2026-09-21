<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations Phase 3 — calculated result snapshot.
 *
 * Responsibility split (kept deliberately strict):
 *
 *   ExamMark  = source of truth for ENTERED marks
 *   ExamResult / ExamResultItem = a RECALCULABLE SNAPSHOT produced by the
 *                                 calculation engine
 *
 * This table never stores marks that ExamMark does not already own, and no
 * student / program / section / subject / academic-year / academic-term data is
 * copied onto it — everything is derived through relationships.
 *
 * Three independent status dimensions are kept apart (never overloaded into
 * one field):
 *   - calculation_status : pending | calculated | incomplete | failed
 *   - result_status      : pass | fail | absent | withheld | incomplete
 *   - publication status : derived from published_at (published/unpublished)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examination_id')->constrained('examinations')->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            // The grading configuration this snapshot was produced with, when
            // one was selected. Nullable: pass/fail is derived from the papers'
            // own passing marks, so a result is valid even without a scale.
            $table->foreignId('grade_scale_id')->nullable()->constrained('grade_scales')->nullOnDelete();

            $table->decimal('total_max_marks', 12, 2)->default(0);
            $table->decimal('total_obtained_marks', 12, 2)->nullable();
            $table->decimal('percentage', 6, 3)->nullable();
            $table->string('overall_grade', 20)->nullable();

            $table->string('result_status', 20)->default('incomplete')->index();
            $table->string('calculation_status', 20)->default('pending')->index();

            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'examination_id'], 'exam_results_college_exam_idx');
            $table->index(['college_id', 'examination_id', 'result_status'], 'exam_results_exam_status_idx');
            $table->index(['college_id', 'examination_id', 'calculation_status'], 'exam_results_exam_calc_idx');
            $table->index(['college_id', 'student_enrollment_id'], 'exam_results_college_enrollment_idx');
            $table->index(['examination_id', 'published_at'], 'exam_results_publication_idx');
        });

        // At most one ACTIVE calculated result per
        // (college, examination, student enrollment). Soft-deleted rows are
        // history and never block recalculation. SQLite and Postgres support
        // partial unique indexes; MySQL/MariaDB relies on the application-level
        // updateOrCreate guard in ResultCalculationService.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX exam_results_active_unique ON exam_results (college_id, examination_id, student_enrollment_id) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
