<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management Phase 2 — Hostel Allocation.
 *
 * Allocates an EXISTING StudentEnrollment to an EXISTING HostelBed for an
 * academic year. No student, enrollment, academic year, hostel, building,
 * room or bed masters are duplicated — every column is a reference.
 *
 * The allocation becomes the source of truth for bed occupancy: the
 * HostelBed.status flag is kept synchronized transactionally but never
 * allowed to become an independent conflicting source.
 *
 * Business invariants enforced at DB + service layer:
 * - bed can have only ONE active allocation at a time
 * - enrollment can have only ONE active allocation per academic year
 * - hierarchy must belong to same college (service validates building→hostel,
 *   room→building+hostel, bed→room+building+hostel)
 * - vacated_date >= allocation_date, active allocations have no vacated_date
 * - historical allocations preserved (soft deletes)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ensure hostel_beds can be referenced by (id, college_id) composite FK,
        // mirroring hostel_buildings and hostel_rooms.
        Schema::table('hostel_beds', function (Blueprint $table) {
            $table->unique(['id', 'college_id'], 'hostel_beds_id_college_unique');
        });

        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            // Composite FKs guarantee same-tenant ownership at DB level.
            $table->unsignedBigInteger('hostel_id');
            $table->foreign(['hostel_id', 'college_id'])->references(['id', 'college_id'])->on('hostels')->restrictOnDelete();

            $table->unsignedBigInteger('hostel_building_id');
            $table->foreign(['hostel_building_id', 'college_id'])->references(['id', 'college_id'])->on('hostel_buildings')->restrictOnDelete();

            $table->unsignedBigInteger('hostel_room_id');
            $table->foreign(['hostel_room_id', 'college_id'])->references(['id', 'college_id'])->on('hostel_rooms')->restrictOnDelete();

            $table->unsignedBigInteger('hostel_bed_id');
            $table->foreign(['hostel_bed_id', 'college_id'])->references(['id', 'college_id'])->on('hostel_beds')->restrictOnDelete();

            $table->date('allocation_date');
            $table->date('vacated_date')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'academic_year_id', 'status'], 'hostel_allocations_year_status_idx');
            $table->index(['college_id', 'hostel_id'], 'hostel_allocations_hostel_idx');
            $table->index(['college_id', 'hostel_building_id'], 'hostel_allocations_building_idx');
            $table->index(['college_id', 'hostel_room_id'], 'hostel_allocations_room_idx');
            $table->index(['college_id', 'hostel_bed_id'], 'hostel_allocations_bed_idx');
            $table->index(['college_id', 'student_enrollment_id'], 'hostel_allocations_enrollment_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            // One active allocation per bed.
            DB::statement("CREATE UNIQUE INDEX hostel_allocations_bed_active_unique ON hostel_allocations (college_id, hostel_bed_id) WHERE deleted_at IS NULL AND status = 'active'");
            // One active allocation per enrollment + academic year.
            DB::statement("CREATE UNIQUE INDEX hostel_allocations_enrollment_year_active_unique ON hostel_allocations (college_id, student_enrollment_id, academic_year_id) WHERE deleted_at IS NULL AND status = 'active'");
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL cannot create partial unique indexes; service layer + locking prevents duplicates.
            // Add CHECK for vacated_date logic where supported.
            DB::statement('ALTER TABLE `hostel_allocations` ADD CONSTRAINT `hostel_allocations_dates_check` CHECK (`vacated_date` IS NULL OR `vacated_date` >= `allocation_date`)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "hostel_allocations" ADD CONSTRAINT "hostel_allocations_dates_check" CHECK ("vacated_date" IS NULL OR "vacated_date" >= "allocation_date")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_allocations');

        Schema::table('hostel_beds', function (Blueprint $table) {
            $table->dropUnique('hostel_beds_id_college_unique');
        });
    }
};
