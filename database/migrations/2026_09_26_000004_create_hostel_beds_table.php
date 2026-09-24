<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management (Phase 1) — beds inside a room. The leaf of the
 * college → hostel → building → room → bed hierarchy.
 *
 * A bed belongs to exactly one room of the same college; the composite
 * foreign key `(room_id, college_id) → hostel_rooms (id, college_id)`
 * enforces that at the database level. `hostel_id` and `building_id` are
 * denormalized copies stamped by the service from the owning room.
 *
 * `status` (`available`, `occupied`, `inactive`) is a Phase 1 operational
 * flag only: from Phase 2 the Hostel Allocation module — which will connect
 * existing StudentEnrollment records to beds — becomes the source of truth
 * for occupancy. Nothing here allocates a student and no student table is
 * touched.
 *
 * `bed_number` identifies the bed within its room and stays reserved on
 * archived records, so future allocation/history references can never become
 * ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('hostel_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('room_id');
            $table->foreign(['room_id', 'college_id'])->references(['id', 'college_id'])->on('hostel_rooms')->restrictOnDelete();
            $table->string('bed_number', 50);
            $table->string('status', 255);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'room_id', 'bed_number']);
            $table->index(['college_id', 'hostel_id']);
            $table->index(['college_id', 'building_id']);
            $table->index(['college_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_beds');
    }
};
