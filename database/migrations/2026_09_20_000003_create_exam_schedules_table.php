<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examination_id')->constrained('examinations')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room', 100)->nullable();
            $table->decimal('max_marks', 6, 2);
            $table->decimal('passing_marks', 6, 2);
            $table->string('status', 20)->default('scheduled')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'examination_id']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'academic_term_id']);
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'section_id']);
            $table->index(['college_id', 'subject_id']);
            $table->index(['college_id', 'faculty_id']);
            $table->index(['college_id', 'campus_id']);
            $table->index(['college_id', 'exam_date']);
            $table->index(['examination_id', 'section_id', 'subject_id', 'exam_date', 'start_time'], 'exam_sched_dup_idx');
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX exam_schedules_active_unique ON exam_schedules (examination_id, section_id, subject_id, exam_date, start_time) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_schedules');
    }
};
