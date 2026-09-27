<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name', 120);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['college_id', 'type', 'is_active']);
        });

        // Non-TC issuance only. TC issuance and numbering stay on the existing
        // StudentTransfer row to avoid a second TC record/data model.
        Schema::create('certificate_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();
            $table->string('type', 32);
            $table->string('certificate_number', 64);
            $table->date('issued_at');
            $table->text('purpose')->nullable();
            $table->longText('rendered_content')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['college_id', 'certificate_number']);
            $table->index(['college_id', 'type', 'issued_at']);
            $table->index(['college_id', 'student_id']);
        });

        Schema::create('certificate_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();
            $table->string('type', 32);
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['college_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_requests');
        Schema::dropIfExists('certificate_issuances');
        Schema::dropIfExists('certificate_templates');
    }
};
