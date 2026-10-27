<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Year-of-study / semester of one enrollment.
 *
 * The registration wizard's academic step captures "semester / year" beside the
 * academic year, programme and section. Nothing in the schema held it: the
 * academic year names the session (2026-27), the section names a batch, and
 * neither says which semester the student starts in. A free-text `semester`
 * (e.g. "1", "3", "Semester 5") is added rather than a master table so the
 * column works for annual, semester and trimester systems alike. Nullable:
 * existing enrollments and every other creation path keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->string('semester', 30)->nullable()->after('section_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropColumn('semester');
        });
    }
};
