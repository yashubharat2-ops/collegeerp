<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admission_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            // Applicant is the canonical person record — enquiry must reference it to avoid duplication.
            // Cascade on hard delete (college/applicant hard delete); soft delete of applicant does not hard delete enquiry,
            // but business logic may block or soft delete via model events in future.
            $table->foreignId('applicant_id')->constrained('admission_applicants')->cascadeOnDelete();
            // Academic context: nullable nullOnDelete to preserve enquiry if year/program deleted (soft delete in practice).
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('enquiry_number', 50);
            $table->string('source', 100)->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->text('remarks')->nullable();
            $table->timestamp('enquired_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Unique per college: same number may exist in another college.
            $table->unique(['college_id', 'enquiry_number']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'applicant_id']);
            $table->index(['college_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_enquiries');
    }
};
