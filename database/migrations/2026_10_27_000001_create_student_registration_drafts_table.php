<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A registration is not yet a Student, and student_documents requires a
        // student and a real file. Keep the working copy separate until submit.
        Schema::create('student_registration_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Both payloads are encrypted by the model, including identity data
            // and the private, server-generated paths of staged attachments.
            $table->longText('data');
            $table->longText('attachments');
            $table->string('status', 20)->default('draft');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'created_by', 'status'], 'registration_drafts_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_registration_drafts');
    }
};
