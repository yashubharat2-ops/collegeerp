<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the ROLL NUMBER to student enrollments.
 *
 * Why a new column: the schema had no student-level roll number at all (the
 * only `roll_number` in the database belongs to `student_qualifications`, i.e.
 * the roll number of a *previous* examination). A roll number is part of the
 * academic assignment — it is the position of a student in a class/section for
 * one academic year — so it belongs on the enrollment, not on `students`: a
 * student gets a new one when they move to another year/section, and their
 * permanent Student ID must not change when that happens.
 *
 * Uniqueness scope: one roll number per (college, academic year, programme,
 * section). The same number is therefore allowed in another section, programme
 * or year, which is how class registers are actually numbered.
 *
 * Safety:
 *  - the column is nullable, so every existing enrollment keeps working and no
 *    production row is rewritten;
 *  - the unique index is additive and only constrains rows that carry a number
 *    (SQL treats NULLs as distinct, on MySQL and SQLite alike), so existing
 *    enrollments without one are unaffected;
 *  - a soft-deleted enrollment is history and does not occupy a number in the
 *    application's allocation logic (the index cannot express that, so the
 *    allocator is the authority and the index is the backstop).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->string('roll_number', 30)->nullable()->after('semester');

            $table->unique(
                ['college_id', 'academic_year_id', 'program_id', 'section_id', 'roll_number'],
                'student_enrollments_scope_roll_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('student_enrollments_scope_roll_number_unique');
            $table->dropColumn('roll_number');
        });
    }
};
