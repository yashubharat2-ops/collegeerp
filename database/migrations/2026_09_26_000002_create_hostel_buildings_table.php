<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management (Phase 1) — buildings / blocks inside a hostel.
 *
 * Every building belongs to exactly one hostel of the same college. The
 * composite foreign key `(hostel_id, college_id) → hostels (id, college_id)`
 * makes same-tenant ownership a database-level guarantee, not only an
 * application rule (same approach as transport_stops).
 *
 * `code` identifies the block within its hostel (stored upper-cased; unique
 * per college + hostel and reserved on archived records). `floors` is the
 * optional number of floors; when present, rooms can be checked against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('hostel_id');
            $table->foreign(['hostel_id', 'college_id'])->references(['id', 'college_id'])->on('hostels')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('code', 50);
            $table->unsignedInteger('floors')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'hostel_id', 'code']);
            // Composite tenant anchor for child foreign keys.
            $table->unique(['id', 'college_id']);
            $table->index(['college_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_buildings');
    }
};
