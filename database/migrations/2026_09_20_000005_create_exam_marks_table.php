<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations Phase 2 — Marks Entry.
 *
 * Additive only: an ExamMark references an existing ExamSchedule and an
 * existing StudentEnrollment. Student, program, section, subject, academic
 * year and term are always derived from those relationships — never copied.
 *
 * Phase 2 intentionally stops at data capture (draft / entered / absent /
 * withheld). Result calculation, publishing and marksheets are later phases.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_schedule_id')->constrained('exam_schedules')->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->cascadeOnDelete();
            $table->decimal('max_marks', 6, 2);
            $table->decimal('passing_marks', 6, 2);
            // Obtained marks stay NULL while the record is a draft or when the
            // student is absent / the marks are withheld — see ExamMark model.
            $table->decimal('obtained_marks', 6, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('entered_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'exam_schedule_id']);
            $table->index(['college_id', 'student_enrollment_id']);
        });

        // At most one ACTIVE mark row per (college, schedule, enrollment).
        // Soft-deleted rows are history and never block re-entry. SQLite and
        // Postgres support partial unique indexes; MySQL/MariaDB cannot express
        // "unique where not soft-deleted", so it relies on the same application
        // level updateOrCreate guard the exam_schedules module uses.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX exam_marks_active_unique ON exam_marks (college_id, exam_schedule_id, student_enrollment_id) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_marks');
    }
};
