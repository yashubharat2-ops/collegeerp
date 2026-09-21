<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations Phase 3 — per-subject outcome of a calculated result.
 *
 * A snapshot of one ExamSchedule's outcome inside one ExamResult. Subject,
 * examination, year, term, program and section stay owned by ExamSchedule and
 * are only referenced here — this table is never a second source of truth for
 * marks (ExamMark is).
 *
 * Items follow their parent result and are therefore not soft-deleted, so the
 * "at most one item per result + schedule" rule is a plain unique index that
 * works identically on SQLite, MySQL/MariaDB and Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_result_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_result_id')->constrained('exam_results')->cascadeOnDelete();
            $table->foreignId('exam_schedule_id')->constrained('exam_schedules')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->decimal('max_marks', 6, 2);
            $table->decimal('passing_marks', 6, 2);
            // NULL for absent / withheld / missing marks — never coerced to 0.
            $table->decimal('obtained_marks', 6, 2)->nullable();
            $table->string('grade', 20)->nullable();
            // pass | fail | absent | withheld | incomplete
            $table->string('status', 20)->index();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(['exam_result_id', 'exam_schedule_id'], 'exam_result_items_result_schedule_unique');
            $table->index(['college_id', 'exam_result_id'], 'exam_result_items_college_result_idx');
            $table->index(['exam_schedule_id', 'status'], 'exam_result_items_schedule_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_result_items');
    }
};
