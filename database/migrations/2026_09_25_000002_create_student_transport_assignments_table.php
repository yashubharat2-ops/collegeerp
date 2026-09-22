<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transport Phase 2 — Student Transport Assignment.
 *
 * Assigns an EXISTING StudentEnrollment to an EXISTING transport route/stop
 * for an academic year. No student, enrollment, route or stop masters are
 * duplicated — every column is a reference into the existing tables.
 *
 * The composite foreign keys ((route, college) and (stop, college)) enforce
 * at the database level that the route and the stop belong to the SAME
 * college as the assignment; the additional rule that the stop belongs to the
 * selected route is enforced by the service layer under a row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The composite FK below needs (id, college_id) to be unique on
        // transport_stops (transport_routes already carries that key).
        Schema::table('transport_stops', function (Blueprint $table) {
            $table->unique(['id', 'college_id'], 'transport_stops_id_college_unique');
        });

        Schema::create('student_transport_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->unsignedBigInteger('transport_route_id');
            $table->foreign(['transport_route_id', 'college_id'])
                ->references(['id', 'college_id'])->on('transport_routes')->restrictOnDelete();

            $table->unsignedBigInteger('transport_stop_id');
            $table->foreign(['transport_stop_id', 'college_id'])
                ->references(['id', 'college_id'])->on('transport_stops')->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // History is preserved: completed/cancelled rows stay, soft-deleted
            // rows stay, and only ONE active assignment may exist per
            // enrollment + academic year (guarded by a partial unique index on
            // SQLite/Postgres plus the service-level lock guard everywhere).
            $table->softDeletes();

            $table->index(['college_id', 'academic_year_id', 'status'], 'student_transport_assignments_year_status_idx');
            $table->index(['college_id', 'transport_route_id'], 'student_transport_assignments_route_idx');
            $table->index(['college_id', 'transport_stop_id'], 'student_transport_assignments_stop_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX student_transport_assignments_active_unique ON student_transport_assignments (college_id, student_enrollment_id, academic_year_id) WHERE deleted_at IS NULL AND status = \'active\'');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transport_assignments');

        Schema::table('transport_stops', function (Blueprint $table) {
            $table->dropUnique('transport_stops_id_college_unique');
        });
    }
};
