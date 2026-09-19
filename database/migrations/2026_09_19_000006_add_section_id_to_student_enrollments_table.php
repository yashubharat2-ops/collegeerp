<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds the optional Section / Batch reference to student enrollments.
     *
     * This is an ENHANCEMENT of the existing StudentEnrollment module, not a
     * new module: the Section master data already exists (sections table,
     * created by 2026_09_19_000002_create_sections_table) and is owned by the
     * Platform module. Nothing is duplicated here — the enrollment simply
     * gains a nullable pointer to it, which the Students module needs for:
     *
     * - Student Promotion (source → target section),
     * - Student Academic Records (which section the record belongs to),
     * - Student ID Cards (section printed on the card).
     *
     * Safety:
     * - The column is nullable with nullOnDelete, so every existing enrollment
     *   row keeps working unchanged and no production data is rewritten.
     * - No existing column, index or constraint is modified or dropped.
     * - Tenant safety is unchanged: sections.college_id carries the same
     *   CollegeScope as student_enrollments.college_id, and the write side is
     *   validated with Rule::exists('sections', 'id')->where('college_id', …).
     */
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->foreignId('section_id')
                ->nullable()
                ->after('program_id')
                ->constrained('sections')
                ->nullOnDelete();

            $table->index(['college_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropIndex(['college_id', 'section_id']);
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
