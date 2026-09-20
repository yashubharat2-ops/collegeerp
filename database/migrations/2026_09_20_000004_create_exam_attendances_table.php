<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations Phase 2 — Exam Attendance.
 *
 * Additive only: Exam Attendance links an existing ExamSchedule to an existing
 * StudentEnrollment (no student master data is copied onto the record). All
 * columns below are references into masters owned by Platform / Students /
 * Examinations Phase 1.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_schedule_id')->constrained('exam_schedules')->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->cascadeOnDelete();
            $table->string('attendance_status', 20)->index();
            $table->timestamp('marked_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'exam_schedule_id']);
            $table->index(['college_id', 'student_enrollment_id']);
        });

        // At most one ACTIVE attendance row per (college, schedule, enrollment).
        // Soft-deleted rows are history and never block re-marking. SQLite and
        // Postgres support partial unique indexes; MySQL/MariaDB cannot express
        // "unique where not soft-deleted", so it relies on the same application
        // level updateOrCreate guard the exam_schedules module uses.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX exam_attendances_active_unique ON exam_attendances (college_id, exam_schedule_id, student_enrollment_id) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attendances');
    }
};
