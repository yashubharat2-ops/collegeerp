<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management (Phase 1) — rooms inside a building / block.
 *
 * A room belongs to exactly one building of the same college; the composite
 * foreign key `(building_id, college_id) → hostel_buildings (id, college_id)`
 * enforces that at the database level. `hostel_id` is a denormalized copy of
 * the building's hostel, stamped by the service (never from request input) so
 * the entire hierarchy can be filtered and reported on without joins.
 *
 * `room_number` identifies the room within its building and stays reserved on
 * archived records. `capacity` is the maximum number of beds the room may
 * hold — the application refuses beds (or capacity decreases) that would
 * break that ceiling, so the number of active beds can never exceed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('hostel_id');
            $table->unsignedBigInteger('building_id');
            $table->foreign(['building_id', 'college_id'])->references(['id', 'college_id'])->on('hostel_buildings')->restrictOnDelete();
            $table->string('room_number', 50);
            $table->unsignedInteger('floor')->nullable();
            $table->string('room_type', 100)->nullable();
            $table->unsignedInteger('capacity');
            $table->text('description')->nullable();
            $table->string('status', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'building_id', 'room_number']);
            // Composite tenant anchor for the beds foreign key.
            $table->unique(['id', 'college_id']);
            $table->index(['college_id', 'hostel_id']);
            $table->index(['college_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_rooms');
    }
};
