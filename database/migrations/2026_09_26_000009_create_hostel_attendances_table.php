<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management Phase 3 — Hostel Attendance.
 *
 * One attendance mark for an existing StudentEnrollment on an existing
 * HostelAllocation. No student, enrollment, hostel, building, room or bed
 * master is copied: every column is a reference, and college_id is stamped
 * from the tenant context.
 *
 * HostelAllocation remains the source of truth for residency. Composite
 * foreign keys pin the enrollment and the allocation to the same college.
 *
 * Duplicate prevention: at most one live row per (college, enrollment, date).
 * Soft-deleted rows are history and must not block an authorized re-mark.
 * SQLite/Postgres express that with a partial unique index. MySQL/MariaDB
 * cannot, so a stored generated column is unique only while deleted_at is null
 * (NULL does not collide in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->unique(['id', 'college_id'], 'student_enrollments_id_college_unique');
        });

        Schema::table('hostel_allocations', function (Blueprint $table) {
            $table->unique(['id', 'college_id'], 'hostel_allocations_id_college_unique');
        });

        Schema::create('hostel_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('student_enrollment_id');
            $table->foreign(['student_enrollment_id', 'college_id'], 'hostel_attendances_enrollment_college_fk')
                ->references(['id', 'college_id'])->on('student_enrollments')->restrictOnDelete();

            $table->unsignedBigInteger('hostel_allocation_id');
            $table->foreign(['hostel_allocation_id', 'college_id'], 'hostel_attendances_allocation_college_fk')
                ->references(['id', 'college_id'])->on('hostel_allocations')->restrictOnDelete();

            $table->date('attendance_date');
            $table->string('attendance_status', 20);
            $table->text('remarks')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'attendance_date'], 'hostel_attendances_college_date_idx');
            $table->index(['college_id', 'attendance_status'], 'hostel_attendances_college_status_idx');
            $table->index(['college_id', 'hostel_allocation_id'], 'hostel_attendances_allocation_idx');
        });

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX hostel_attendances_enrollment_date_unique ON hostel_attendances (college_id, student_enrollment_id, attendance_date) WHERE deleted_at IS NULL');
        } else {
            Schema::table('hostel_attendances', function (Blueprint $table) {
                $table->date('live_attendance_date')->nullable()->storedAs('CASE WHEN deleted_at IS NULL THEN attendance_date ELSE NULL END');
                $table->unique(['college_id', 'student_enrollment_id', 'live_attendance_date'], 'hostel_attendances_enrollment_date_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_attendances');

        Schema::table('hostel_allocations', function (Blueprint $table) {
            $table->dropUnique('hostel_allocations_id_college_unique');
        });

        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('student_enrollments_id_college_unique');
        });
    }
};
