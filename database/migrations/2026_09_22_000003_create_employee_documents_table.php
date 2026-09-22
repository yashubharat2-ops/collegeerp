<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            // `faculty_id` deliberately points at the existing Platform
            // Faculty/Staff table. HR does not maintain a duplicate employee
            // master just to attach documents.
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();

            $table->string('document_name', 255);
            $table->string('document_type', 100)->nullable();
            // Optional reuse of the existing tenant document-type master. The
            // free-text type above keeps HR documents independent of admission
            // workflows while this FK allows an existing type to be reused.
            $table->foreignId('document_type_id')->nullable()->constrained('admission_document_types')->nullOnDelete();
            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'faculty_id']);
            $table->index(['college_id', 'document_type']);
            $table->index(['college_id', 'document_type_id']);
            $table->index(['college_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
