<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the admission "category" (reservation / admission category) to the
     * EXISTING students table.
     *
     * Why a column and not a new master table: the Students list must be
     * filterable by category, and every student has exactly one admission
     * category — it is an attribute of the enrollment identity, not a separate
     * record. A `student_categories` master (or a pivot on top of the existing
     * Student/StudentEnrollment records) would duplicate the student master the
     * module already owns, so this milestone deliberately adds one nullable
     * column instead.
     *
     * Why the value set is not an enum/check constraint: institution categories
     * vary (General / OBC / SC / ST / EWS / management quota / …). The column is
     * a plain string; the *convention* lives in `Student::CATEGORIES` and is
     * enforced only at the validation boundary, so a college that needs another
     * category changes configuration, never the schema. This follows the earlier
     * admission design review ("institution-specific fields are added via later
     * migrations only if explicitly required and justified, never as a schema
     * enum") — the Student list's Category filter is that explicit requirement.
     *
     * Safety:
     * - Nullable with no default, so every existing student row keeps working
     *   unchanged and no production data is rewritten or backfilled.
     * - No existing column, index or constraint is modified or dropped.
     * - Tenant safety is unchanged: the column lives on the already tenant-scoped
     *   `students` table (college_id + CollegeScope), and the index follows the
     *   existing `(college_id, …)` pattern used by the other list filters.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('gender');

            $table->index(['college_id', 'category'], 'students_college_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_college_category_idx');
            $table->dropColumn('category');
        });
    }
};
