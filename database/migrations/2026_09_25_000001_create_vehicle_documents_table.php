<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transport Phase 2 — Vehicle Documents.
 *
 * Private, tenant-scoped documents attached to the EXISTING vehicles master
 * (registration / RC, insurance, fitness certificate, permit, PUC, other).
 *
 * `document_type` is an extensible string, not a closed enum: a college may
 * store any document category without a migration, exactly like the payment
 * modes of fee_payments. The application only SUGGESTS the conventional set
 * (see VehicleDocument::TYPES).
 *
 * `file_path` is generated server-side on the private disk by the
 * VehicleDocumentService and is never accepted from a request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            // Documents belong to the existing Transport vehicles master — no
            // duplicate vehicle table is created. restrictOnDelete: a vehicle
            // with documents must be archived (soft-deleted), never hard-removed.
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();

            $table->string('document_type', 100);
            $table->string('document_number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();

            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->text('remarks')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Soft deletes preserve document history: the row (and its audited
            // path snapshot) stays retrievable after a delete.
            $table->softDeletes();

            $table->index(['college_id', 'vehicle_id']);
            $table->index(['college_id', 'document_type']);
            $table->index(['college_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
