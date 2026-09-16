<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->constrained('admission_applications')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('admission_applicants')->cascadeOnDelete();
            $table->string('admission_number', 50);
            $table->date('admission_date')->nullable();
            $table->string('status', 20)->default('active')->index(); // active, cancelled, completed
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'admission_number']);
            $table->unique(['college_id', 'application_id']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'applicant_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'admission_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
