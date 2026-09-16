<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('admission_applicants')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('admission_applications')->nullOnDelete();
            $table->foreignId('document_type_id')->constrained('admission_document_types')->cascadeOnDelete();

            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->string('verification_status', 20)->default('pending')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_remarks')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'applicant_id']);
            $table->index(['college_id', 'application_id']);
            $table->index(['college_id', 'document_type_id']);
            $table->index(['college_id', 'verification_status']);
            $table->index(['applicant_id', 'document_type_id']);
            $table->index(['application_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_documents');
    }
};
