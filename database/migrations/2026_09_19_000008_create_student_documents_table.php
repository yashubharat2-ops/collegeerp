<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tenant-aware student documents.
     *
     * A student may hold many documents (transfer certificate, mark sheets,
     * ID proofs, medical certificates …) across the whole student lifecycle —
     * long after the admission stage has closed.
     *
     * Document TYPE master data is deliberately REUSED, not duplicated:
     * `document_type_id` points at the existing admission_document_types table
     * (created 2026_09_16_000006), which is already tenant scoped, per-college
     * configurable, and carries allowed extensions/MIME/max size. Creating a
     * parallel "student document types" table would duplicate master data and
     * split configuration, so it is intentionally not created. The FK is
     * nullable (nullOnDelete) because a college may upload a student document
     * before modelling its type, and because retiring an admission document
     * type must not orphan or destroy student records.
     *
     * Security:
     * - `file_path` is ALWAYS server-generated (uuid + sanitised extension)
     *   under a tenant-scoped private-disk directory. It is never taken from
     *   the client, so no user-supplied path can ever be stored or served.
     * - Files live on the `private` disk (storage/app/private), which is not
     *   web-served; downloads stream through an authorized controller action.
     * - Rows are soft-deleted and the stored file is retained, so document
     *   history survives deletion (see StudentDocumentService::delete).
     */
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('admission_document_types')->nullOnDelete();

            $table->string('title');
            $table->string('file_path', 500);
            $table->string('original_filename');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();

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

            $table->index(['college_id', 'student_id']);
            $table->index(['college_id', 'document_type_id']);
            $table->index(['college_id', 'verification_status']);
            $table->index(['student_id', 'document_type_id']);
            $table->index(['college_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
