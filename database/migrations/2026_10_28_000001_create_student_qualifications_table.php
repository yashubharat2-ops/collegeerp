<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previous academic qualifications of a student (Class 10, Class 12, Graduation…).
 *
 * WHY A NEW TABLE (and why it is not a duplicate):
 *
 * - `students.previous_*` (from 2026_10_04_000002) holds ONE previous-education
 *   snapshot. The registration wizard must record several qualifications per
 *   student, so those columns stay as the last-qualification summary and this
 *   table becomes the structured detail. Nothing is dropped or migrated.
 * - `student_academic_records` is the student's own progression/status ledger
 *   inside this college (year → term → program → result-agnostic status). It
 *   deliberately carries no marks or external board data, so historical
 *   examination results cannot live there without corrupting its meaning.
 * - `examinations` / `exam_marks` / `exam_results` are THIS institution's
 *   examinations for enrolled students; a Class 12 mark sheet from an external
 *   board is not one of them.
 *
 * So this is the only existing gap: a per-student list of external
 * qualifications with marks. It references the existing `students` row and
 * carries `college_id` for tenant scoping like every other student table.
 *
 * Marks are nullable: a grading-system record legitimately has a grade and no
 * marks, and a marks record may legitimately have no grade. `percentage` is
 * stored only when it was actually calculable from marks/max marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // class_10 | class_12 | graduation | post_graduation | other
            $table->string('level', 30)->index();
            $table->string('qualification', 150)->nullable();

            $table->unsignedSmallInteger('examination_year')->nullable();
            $table->string('board_university', 150)->nullable();
            $table->string('institution', 200)->nullable();

            $table->string('roll_number', 50)->nullable();
            $table->string('registration_number', 80)->nullable();

            // Optional subject-level detail. Each entry is
            // {name, marks_obtained, max_marks, grade} — free-form because the
            // subject master belongs to this college's own programmes, and an
            // external board's subject list is not ours to model.
            $table->json('subjects')->nullable();

            $table->decimal('marks_obtained', 8, 2)->nullable();
            $table->decimal('max_marks', 8, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('grade', 30)->nullable();
            $table->string('grade_letter', 10)->nullable();

            // pass | fail | compartment | awaiting
            $table->string('result_status', 20)->nullable()->index();

            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'student_id']);
            $table->index(['student_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_qualifications');
    }
};
