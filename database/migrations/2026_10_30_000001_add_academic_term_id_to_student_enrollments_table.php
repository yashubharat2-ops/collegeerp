<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the CONFIGURED academic level (AcademicTerm) to an enrollment.
 *
 * Why a second column next to `semester`: `semester` is the human label the
 * office reads ("Semester 3", "Year 2"), while the academic structure is the
 * `academic_terms` master (owned per college and per academic year, with a
 * type, a sequence and a code). Until now the wizard could only store the label,
 * so "Semester 3" from one college/year and "SEM-3" from another were the same
 * string and no report could join on it. The Student Filter already treats a
 * semester as a reference (`academic_context=term:{id}`), and the progression
 * ledger (`student_academic_records.academic_term_id`) already stores that
 * reference; an enrollment could not, which is the gap this closes.
 *
 * Compatibility:
 *  - nullable, so every existing enrollment (and every other creation path —
 *    promotion, admission conversion, import) keeps working untouched;
 *  - `semester` is retained: the label is still written (derived from the term's
 *    name when a term is chosen) so existing reports, ID cards and exports that
 *    read the string keep working;
 *  - the foreign key cascades on delete of a term? No — `nullOnDelete` instead,
 *    because deleting master data must never delete a student's history. The
 *    enrollment keeps the label it was created with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->foreignId('academic_term_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('academic_terms')
                ->nullOnDelete();

            $table->index(['college_id', 'academic_term_id'], 'student_enrollments_college_term_index');
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropIndex('student_enrollments_college_term_index');
            $table->dropConstrainedForeignId('academic_term_id');
        });
    }
};
