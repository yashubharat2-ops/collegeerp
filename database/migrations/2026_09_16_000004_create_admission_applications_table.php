<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('admission_applicants')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            // Optional link to originating enquiry to preserve conversion chain without duplicating data.
            $table->foreignId('enquiry_id')->nullable()->constrained('admission_enquiries')->nullOnDelete();
            $table->string('application_number', 50);
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Unique per college.
            $table->unique(['college_id', 'application_number']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'applicant_id']);
            $table->index(['college_id', 'enquiry_id']);
            $table->index(['applicant_id', 'academic_year_id']);
            $table->index(['college_id', 'academic_year_id', 'program_id']);
            $table->index(['college_id', 'academic_year_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_applications');
    }
};
