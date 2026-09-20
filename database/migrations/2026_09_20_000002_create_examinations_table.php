<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('exam_type', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'code']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'academic_term_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'start_date']);
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX examinations_college_code_active_unique ON examinations (college_id, code) WHERE deleted_at IS NULL');
        } else {
            Schema::table('examinations', function (Blueprint $table) {
                $table->unique(['college_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};
