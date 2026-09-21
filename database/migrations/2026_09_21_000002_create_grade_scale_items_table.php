<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations Phase 3 — the individual grade bands of a GradeScale.
 *
 * Each band maps a percentage range to a grade (and an optional grade point).
 * Ranges are validated in the application layer (min <= max, no overlaps,
 * deterministic sort order) because a database CHECK constraint cannot express
 * "no overlapping ranges within the same scale" portably across SQLite and
 * MySQL/MariaDB.
 *
 * Grade scale items follow their parent scale: they are not soft-deleted
 * themselves, so the active-row uniqueness below is a plain unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_scale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_scale_id')->constrained('grade_scales')->cascadeOnDelete();
            $table->string('grade', 20);
            $table->decimal('min_percentage', 6, 3);
            $table->decimal('max_percentage', 6, 3);
            $table->decimal('grade_point', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            // No active duplicate grade definitions inside one scale.
            $table->unique(['grade_scale_id', 'grade'], 'grade_scale_items_scale_grade_unique');
            $table->index(['grade_scale_id', 'status', 'sort_order'], 'grade_scale_items_ordering_idx');
            $table->index(['college_id', 'grade_scale_id'], 'grade_scale_items_college_scale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_scale_items');
    }
};
